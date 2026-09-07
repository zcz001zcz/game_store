<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\ConflictException;
use GameStore\Core\Http\NotFoundException;
use GameStore\Support\Money;
use GameStore\Support\Uuid;
use PDO;
use PDOException;

final class OrderService
{
	public function __construct(
		private readonly Database $database,
		private readonly PaymentService $payments,
	) {
	}

	/** @return array{order: array<string, mixed>, idempotent: bool} */
	public function create(string $sku, ?string $requestedPublicId = null): array
	{
		$sku = strtoupper(trim($sku));

		if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,99}$/', $sku)) {
			throw new BadRequestException('Invalid SKU');
		}

		$publicId = $requestedPublicId === null || trim($requestedPublicId) === ''
			? Uuid::publicOrderId()
			: trim($requestedPublicId);

		if (!preg_match('/^ord_[A-Za-z0-9_-]{3,70}$/', $publicId)) {
			throw new BadRequestException('Invalid order_id');
		}

		try {
			return $this->database->transaction(function (PDO $pdo) use ($sku, $publicId): array {
				$aggregateLock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
				$aggregateLock->execute(['lock_key' => 'order:' . $publicId]);

				$productStatement = $pdo->prepare('SELECT * FROM products WHERE sku = :sku AND is_active = TRUE');
				$productStatement->execute(['sku' => $sku]);
				$product = $productStatement->fetch();

				if (!is_array($product)) {
					throw new NotFoundException('Product not found');
				}

				$existing = $this->findRaw($pdo, $publicId, true);

				if ($existing !== null) {
					if ((string) $existing['sku'] !== $sku) {
						throw new ConflictException('order_id already belongs to another SKU');
					}

					return ['order' => $this->present($existing), 'idempotent' => true];
				}

				$insert = $pdo->prepare(
					"INSERT INTO orders
					 (id, public_id, product_id, sku, product_name, amount_minor, currency, status)
					 VALUES
					 (:id, :public_id, :product_id, :sku, :product_name, :amount_minor, :currency, 'created')"
				);
				$insert->execute([
					'id' => Uuid::v4(),
					'public_id' => $publicId,
					'product_id' => $product['id'],
					'sku' => $product['sku'],
					'product_name' => $product['name'],
					'amount_minor' => $product['price_minor'],
					'currency' => trim((string) $product['currency']),
				]);

				$this->payments->replayPendingForOrder($pdo, $publicId);
				$order = $this->findRaw($pdo, $publicId, false);

				if ($order === null) {
					throw new \LogicException('Created order cannot be loaded');
				}

				return ['order' => $this->present($order), 'idempotent' => false];
			});
		} catch (PDOException $exception) {
			if ((string) $exception->getCode() !== '23505') {
				throw $exception;
			}

			$existing = $this->findRaw($this->database->connection(), $publicId, false);

			if ($existing === null || (string) $existing['sku'] !== $sku) {
				throw new ConflictException('order_id already exists');
			}

			return ['order' => $this->present($existing), 'idempotent' => true];
		}
	}

	/** @return array<string, mixed> */
	public function get(string $publicId): array
	{
		$order = $this->findRaw($this->database->connection(), $publicId, false);

		if ($order === null) {
			throw new NotFoundException('Order not found');
		}

		return $this->present($order);
	}

	/** @return list<array<string, mixed>> */
	public function catalog(int $limit, ?int $afterId): array
	{
		$limit = max(1, min($limit, 100));
		$afterId = max(0, $afterId ?? 0);
		$statement = $this->database->connection()->prepare(
			'SELECT p.id, p.sku, p.name, p.type, p.price_minor, p.currency, p.image_path, '
			. '(SELECT COUNT(*) FROM provider_stock ps '
			. ' WHERE ps.sku = p.sku AND ps.issued_at IS NULL) AS available_count '
			. 'FROM products p WHERE p.is_active = TRUE AND p.id > :after_id '
			. 'ORDER BY p.id ASC LIMIT :limit'
		);
		$statement->bindValue('after_id', $afterId, PDO::PARAM_INT);
		$statement->bindValue('limit', $limit, PDO::PARAM_INT);
		$statement->execute();
		$result = [];

		while ($product = $statement->fetch()) {
			if (!is_array($product)) {
				continue;
			}

			$result[] = [
				'id' => (int) $product['id'],
				'sku' => $product['sku'],
				'name' => $product['name'],
				'type' => $product['type'],
				'price' => [
					'amount' => Money::fromMinor((int) $product['price_minor']),
					'currency' => trim((string) $product['currency']),
				],
				'image' => $product['image_path'],
				'stock' => [
					'available' => (int) $product['available_count'],
					'in_stock' => (int) $product['available_count'] > 0,
				],
			];
		}

		return $result;
	}

	/** @return array<string, mixed>|null */
	private function findRaw(PDO $pdo, string $publicId, bool $forUpdate): ?array
	{
		$sql = 'SELECT * FROM orders WHERE public_id = :public_id' . ($forUpdate ? ' FOR UPDATE' : '');
		$statement = $pdo->prepare($sql);
		$statement->execute(['public_id' => $publicId]);
		$order = $statement->fetch();

		return is_array($order) ? $order : null;
	}

	/**
	 * @param array<string, mixed> $order
	 * @return array<string, mixed>
	 */
	private function present(array $order): array
	{
		$result = [
			'id' => $order['public_id'],
			'sku' => $order['sku'],
			'product_name' => $order['product_name'],
			'status' => $order['status'],
			'price' => [
				'amount' => Money::fromMinor((int) $order['amount_minor']),
				'currency' => trim((string) $order['currency']),
			],
			'created_at' => $order['created_at'],
			'updated_at' => $order['updated_at'],
		];

		if ((string) $order['status'] === 'delivered') {
			$result['delivery'] = [
				'code' => $order['delivery_code'],
				'delivered_at' => $order['delivered_at'],
			];
		}

		return $result;
	}
}
