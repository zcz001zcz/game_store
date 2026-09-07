<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Order\OrderStatus;
use GameStore\Domain\Provider\RetryableDeliveryException;
use GameStore\Infrastructure\Provider\ProviderHttpClient;
use PDO;

final class DeliveryService
{
	public function __construct(
		private readonly Database $database,
		private readonly ProviderHttpClient $providers,
		private readonly JsonLogger $logger,
	) {
	}

	public function deliver(string $orderPublicId): void
	{
		$order = $this->prepareOrder($orderPublicId);

		if ($order === null) {
			return;
		}

		$failureKinds = [];

		foreach (['A', 'B'] as $provider) {
			$attempt = $this->getOrCreateAttempt($order, $provider);

			if ((string) $attempt['status'] === 'succeeded') {
				$code = (string) ($attempt['code'] ?? '');

				if ($code === '') {
					throw new RetryableDeliveryException('Succeeded attempt has no code');
				}

				$this->finalize($order, $attempt, $code);

				return;
			}

			if ((string) $attempt['status'] === 'definitive_failed') {
				$failureKinds[] = (string) ($attempt['failure_kind'] ?? 'unavailable');
				continue;
			}

			$this->markAttemptStarted((int) $attempt['id']);
			$response = $this->providers->issue(
				$provider,
				(string) $attempt['request_id'],
				(string) $order['sku'],
				(string) $order['public_id'],
			);

			if ($response->isSuccess()) {
				$code = (string) $response->code;
				$this->finalize($order, $attempt, $code);
				$this->logger->info('order_delivered', [
					'order_id' => $order['public_id'],
					'provider' => $provider,
					'request_id' => $attempt['request_id'],
				]);

				return;
			}

			if ($response->isUncertain()) {
				$this->markAttemptUncertain((int) $attempt['id'], (string) $response->reason);
				$this->logger->warning('provider_result_uncertain', [
					'order_id' => $order['public_id'],
					'provider' => $provider,
					'request_id' => $attempt['request_id'],
					'reason' => $response->reason,
				]);

				throw new RetryableDeliveryException(
					sprintf('Provider %s result is uncertain: %s', $provider, $response->reason)
				);
			}

			$failureKind = $response->outcome === 'out_of_stock' ? 'out_of_stock' : 'unavailable';
			$this->markAttemptDefinitiveFailure((int) $attempt['id'], $failureKind, (string) $response->reason);
			$failureKinds[] = $failureKind;
		}

		$finalStatus = $failureKinds !== []
			&& count(array_unique($failureKinds)) === 1
			&& $failureKinds[0] === 'out_of_stock'
			? OrderStatus::OutOfStock
			: OrderStatus::DeliveryFailed;

		$this->markOrderDeliveryFailure((string) $order['id'], $finalStatus);
		$this->logger->warning('order_delivery_not_completed', [
			'order_id' => $order['public_id'],
			'status' => $finalStatus->value,
			'provider_failures' => $failureKinds,
		]);
	}

	public function markExhausted(string $orderPublicId, string $reason): void
	{
		$statement = $this->database->connection()->prepare(
			"UPDATE orders SET status = 'delivery_failed', version = version + 1, updated_at = NOW()
			 WHERE public_id = :public_id AND status IN ('paid', 'delivering')"
		);
		$statement->execute(['public_id' => $orderPublicId]);
		$this->logger->error('order_delivery_retries_exhausted', [
			'order_id' => $orderPublicId,
			'reason' => $reason,
		]);
	}

	/** @return array<string, mixed>|null */
	private function prepareOrder(string $publicId): ?array
	{
		return $this->database->transaction(function (PDO $pdo) use ($publicId): ?array {
			$statement = $pdo->prepare('SELECT * FROM orders WHERE public_id = :public_id FOR UPDATE');
			$statement->execute(['public_id' => $publicId]);
			$order = $statement->fetch();

			if (!is_array($order)) {
				throw new \RuntimeException('Order not found: ' . $publicId);
			}

			$status = OrderStatus::from((string) $order['status']);

			if ($status === OrderStatus::Delivered) {
				return null;
			}

			if (!$status->isRecoverableDeliveryState()) {
				throw new \RuntimeException('Order is not paid and cannot be delivered: ' . $publicId);
			}

			if ($status !== OrderStatus::Delivering) {
				$update = $pdo->prepare(
					"UPDATE orders SET status = 'delivering', version = version + 1, updated_at = NOW()
					 WHERE id = :id"
				);
				$update->execute(['id' => $order['id']]);
				$order['status'] = OrderStatus::Delivering->value;
			}

			return $order;
		});
	}

	/**
	 * @param array<string, mixed> $order
	 * @return array<string, mixed>
	 */
	private function getOrCreateAttempt(array $order, string $provider): array
	{
		return $this->database->transaction(function (PDO $pdo) use ($order, $provider): array {
			$requestId = 'req_' . strtolower($provider) . '_' . substr(
				hash('sha256', $provider . ':' . $order['public_id']),
				0,
				28,
			);
			$insert = $pdo->prepare(
				'INSERT INTO delivery_attempts (order_id, provider, request_id) '
				. 'VALUES (:order_id, :provider, :request_id) '
				. 'ON CONFLICT (order_id, provider) DO NOTHING'
			);
			$insert->execute([
				'order_id' => $order['id'],
				'provider' => $provider,
				'request_id' => $requestId,
			]);

			$select = $pdo->prepare(
				'SELECT * FROM delivery_attempts WHERE order_id = :order_id AND provider = :provider FOR UPDATE'
			);
			$select->execute(['order_id' => $order['id'], 'provider' => $provider]);
			$attempt = $select->fetch();

			if (!is_array($attempt)) {
				throw new \LogicException('Delivery attempt could not be loaded');
			}

			return $attempt;
		});
	}

	private function markAttemptStarted(int $attemptId): void
	{
		$statement = $this->database->connection()->prepare(
			"UPDATE delivery_attempts SET status = 'uncertain', failure_kind = 'in_flight',
			 attempt_count = attempt_count + 1, last_error = NULL, updated_at = NOW()
			 WHERE id = :id"
		);
		$statement->execute(['id' => $attemptId]);
	}

	private function markAttemptUncertain(int $attemptId, string $reason): void
	{
		$statement = $this->database->connection()->prepare(
			"UPDATE delivery_attempts SET status = 'uncertain', failure_kind = 'uncertain',
			 last_error = :reason, updated_at = NOW() WHERE id = :id"
		);
		$statement->execute(['reason' => substr($reason, 0, 4000), 'id' => $attemptId]);
	}

	private function markAttemptDefinitiveFailure(int $attemptId, string $kind, string $reason): void
	{
		$statement = $this->database->connection()->prepare(
			"UPDATE delivery_attempts SET status = 'definitive_failed', failure_kind = :kind,
			 last_error = :reason, updated_at = NOW() WHERE id = :id"
		);
		$statement->execute([
			'kind' => $kind,
			'reason' => substr($reason, 0, 4000),
			'id' => $attemptId,
		]);
	}

	/**
	 * @param array<string, mixed> $order
	 * @param array<string, mixed> $attempt
	 */
	private function finalize(array $order, array $attempt, string $code): void
	{
		if ($code === '') {
			throw new RetryableDeliveryException('Provider returned an empty code');
		}

		$this->database->transaction(function (PDO $pdo) use ($order, $attempt, $code): void {
			$lock = $pdo->prepare('SELECT status, delivery_code FROM orders WHERE id = :id FOR UPDATE');
			$lock->execute(['id' => $order['id']]);
			$current = $lock->fetch();

			if (!is_array($current)) {
				throw new \RuntimeException('Order disappeared during delivery');
			}

			if ((string) $current['status'] === OrderStatus::Delivered->value) {
				if (!hash_equals((string) $current['delivery_code'], $code)) {
					throw new \RuntimeException('Order is already delivered with a different code');
				}

				return;
			}

			$attemptUpdate = $pdo->prepare(
				"UPDATE delivery_attempts SET status = 'succeeded', code = :code,
				 failure_kind = NULL, last_error = NULL, updated_at = NOW() WHERE id = :id"
			);
			$attemptUpdate->execute(['code' => $code, 'id' => $attempt['id']]);

			$fulfillment = $pdo->prepare(
				'INSERT INTO fulfillments '
				. '(order_id, delivery_attempt_id, provider, request_id, code) '
				. 'VALUES (:order_id, :attempt_id, :provider, :request_id, :code)'
			);
			$fulfillment->execute([
				'order_id' => $order['id'],
				'attempt_id' => $attempt['id'],
				'provider' => trim((string) $attempt['provider']),
				'request_id' => $attempt['request_id'],
				'code' => $code,
			]);

			$update = $pdo->prepare(
				"UPDATE orders SET status = 'delivered', delivery_code = :code, delivered_at = NOW(),
				 version = version + 1, updated_at = NOW() WHERE id = :id"
			);
			$update->execute(['code' => $code, 'id' => $order['id']]);
		});
	}

	private function markOrderDeliveryFailure(string $orderId, OrderStatus $status): void
	{
		$statement = $this->database->connection()->prepare(
			'UPDATE orders SET status = :status, version = version + 1, updated_at = NOW() '
			. "WHERE id = :id AND status <> 'delivered'"
		);
		$statement->execute(['status' => $status->value, 'id' => $orderId]);
	}
}
