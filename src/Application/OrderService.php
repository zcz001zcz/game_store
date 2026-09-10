<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\ConflictException;
use GameStore\Core\Http\NotFoundException;
use GameStore\Support\Money;
use GameStore\Support\Uuid;
use JsonException;
use PDO;
use PDOException;

final class OrderService
{
    public function __construct(
        private readonly Database $database,
        private readonly PaymentService $payments,
        private readonly OrderEventStore $events,
    ) {
    }

    /**
     * Accepts both the legacy {sku} contract and the stage-2 {items: [...]} contract.
     * @param array<string, mixed> $payload
     * @return array{order: array<string, mixed>, idempotent: bool}
     */
    public function create(array $payload): array
    {
        $requestedPublicId = $payload['order_id'] ?? null;

        if ($requestedPublicId !== null && !is_string($requestedPublicId)) {
            throw new BadRequestException('order_id must be a string');
        }

        $publicId = !is_string($requestedPublicId) || trim($requestedPublicId) === ''
            ? Uuid::publicOrderId()
            : trim($requestedPublicId);

        if (!preg_match('/^ord_[A-Za-z0-9_-]{3,70}$/', $publicId)) {
            throw new BadRequestException('Invalid order_id');
        }

        $legacy = array_key_exists('sku', $payload);

        if ($legacy && array_key_exists('items', $payload)) {
            throw new BadRequestException('Use either sku or items, not both');
        }

        if ($legacy) {
            if (!is_string($payload['sku'])) {
                throw new BadRequestException('sku must be a string');
            }

            $items = [[
                'sku' => $this->normalizeSku($payload['sku']),
                'provider' => 'A',
                'allow_fallback' => true,
                'refund_on_failure' => false,
            ]];
        } else {
            $items = $this->parseItems($payload['items'] ?? null);
        }

        try {
            $fingerprint = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new \LogicException('Normalized order cannot be encoded', 0, $exception);
        }

        try {
            return $this->database->transaction(function (PDO $pdo) use ($items, $publicId, $fingerprint, $legacy): array {
                $aggregateLock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
                $aggregateLock->execute(['lock_key' => 'order:' . $publicId]);
                $existing = $this->findRaw($pdo, $publicId, true);

                if ($existing !== null) {
                    $storedFingerprint = (string) ($existing['request_fingerprint'] ?? '');
                    $compatibleLegacy = $legacy && $storedFingerprint === ''
                        && (string) $existing['sku'] === (string) $items[0]['sku'];

                    if (!$compatibleLegacy && !hash_equals($storedFingerprint, $fingerprint)) {
                        throw new ConflictException('order_id already belongs to a different basket');
                    }

                    return ['order' => $this->present($pdo, $existing), 'idempotent' => true];
                }

                $productStatement = $pdo->prepare('SELECT * FROM products WHERE sku = :sku AND is_active = TRUE');
                $resolved = [];
                $totalMinor = 0;
                $currency = null;

                foreach ($items as $index => $item) {
                    $productStatement->execute(['sku' => $item['sku']]);
                    $product = $productStatement->fetch();

                    if (!is_array($product)) {
                        throw new NotFoundException('Product not found: ' . $item['sku']);
                    }

                    $productCurrency = trim((string) $product['currency']);

                    if ($currency !== null && $currency !== $productCurrency) {
                        throw new BadRequestException('All order items must use one currency');
                    }

                    $currency = $productCurrency;
                    $totalMinor += (int) $product['price_minor'];
                    $resolved[] = ['input' => $item, 'product' => $product, 'line_no' => $index + 1];
                }

                $first = $resolved[0]['product'];
                $orderId = Uuid::v4();
                $insert = $pdo->prepare(
                    "INSERT INTO orders
                     (id, public_id, product_id, sku, product_name, amount_minor, currency, status,
                      request_fingerprint, item_count)
                     VALUES
                     (:id, :public_id, :product_id, :sku, :product_name, :amount_minor, :currency,
                      'created', :fingerprint, :item_count)"
                );
                $insert->execute([
                    'id' => $orderId,
                    'public_id' => $publicId,
                    'product_id' => $first['id'],
                    'sku' => $first['sku'],
                    'product_name' => $first['name'],
                    'amount_minor' => $totalMinor,
                    'currency' => $currency,
                    'fingerprint' => $fingerprint,
                    'item_count' => count($resolved),
                ]);

                $itemInsert = $pdo->prepare(
                    "INSERT INTO order_items
                     (id, order_id, line_no, product_id, sku, product_name, amount_minor, currency,
                      provider, allow_fallback, refund_on_failure)
                     VALUES
                     (:id, :order_id, :line_no, :product_id, :sku, :product_name, :amount_minor,
                      :currency, :provider, :allow_fallback, :refund_on_failure)"
                );

                foreach ($resolved as $line) {
                    $input = $line['input'];
                    $product = $line['product'];
                    $itemInsert->execute([
                        'id' => Uuid::v4(),
                        'order_id' => $orderId,
                        'line_no' => $line['line_no'],
                        'product_id' => $product['id'],
                        'sku' => $product['sku'],
                        'product_name' => $product['name'],
                        'amount_minor' => $product['price_minor'],
                        'currency' => trim((string) $product['currency']),
                        'provider' => $input['provider'],
                        'allow_fallback' => $input['allow_fallback'] ? 'true' : 'false',
                        'refund_on_failure' => $input['refund_on_failure'] ? 'true' : 'false',
                    ]);
                }

                $this->events->append($pdo, $orderId, 'order.created', 'order-created:' . $orderId);
                $this->payments->replayPendingForOrder($pdo, $publicId);
                $order = $this->findRaw($pdo, $publicId, false);

                if ($order === null) {
                    throw new \LogicException('Created order cannot be loaded');
                }

                return ['order' => $this->present($pdo, $order), 'idempotent' => false];
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $existing = $this->findRaw($this->database->connection(), $publicId, false);

            if ($existing === null || !hash_equals((string) ($existing['request_fingerprint'] ?? ''), $fingerprint)) {
                throw new ConflictException('order_id already exists');
            }

            return ['order' => $this->present($this->database->connection(), $existing), 'idempotent' => true];
        }
    }

    /** @return array<string, mixed> */
    public function get(string $publicId): array
    {
        $pdo = $this->database->connection();
        $order = $this->findRaw($pdo, $publicId, false);

        if ($order === null) {
            throw new NotFoundException('Order not found');
        }

        return $this->present($pdo, $order);
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

    /** @return list<array{sku: string, provider: string, allow_fallback: bool, refund_on_failure: bool}> */
    private function parseItems(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new BadRequestException('items must be a non-empty array');
        }

        if (count($value) > 50) {
            throw new BadRequestException('An order may contain at most 50 items');
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new BadRequestException('Each item must be an object', ['line' => $index + 1]);
            }

            $sku = isset($item['sku']) && is_string($item['sku']) ? $this->normalizeSku($item['sku']) : '';
            $provider = isset($item['provider']) && is_string($item['provider'])
                ? strtoupper(trim($item['provider']))
                : '';

            if ($sku === '') {
                throw new BadRequestException('Item sku is required', ['line' => $index + 1]);
            }

            if (!in_array($provider, ['A', 'B'], true)) {
                throw new BadRequestException('Item provider must be A or B', ['line' => $index + 1]);
            }

            if (isset($item['allow_fallback']) && !is_bool($item['allow_fallback'])) {
                throw new BadRequestException('allow_fallback must be boolean', ['line' => $index + 1]);
            }

            $items[] = [
                'sku' => $sku,
                'provider' => $provider,
                'allow_fallback' => $item['allow_fallback'] ?? false,
                'refund_on_failure' => true,
            ];
        }

        return $items;
    }

    private function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));

        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,99}$/', $sku)) {
            throw new BadRequestException('Invalid SKU');
        }

        return $sku;
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

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function present(PDO $pdo, array $order): array
    {
        $itemsStatement = $pdo->prepare(
            'SELECT oi.*, r.created_at AS refunded_at, r.reason AS refund_reason '
            . 'FROM order_items oi LEFT JOIN refunds r ON r.order_item_id = oi.id '
            . 'WHERE oi.order_id = :order_id ORDER BY oi.line_no ASC'
        );
        $itemsStatement->execute(['order_id' => $order['id']]);
        $items = [];
        $deliveredMinor = 0;

        while ($item = $itemsStatement->fetch()) {
            if (!is_array($item)) {
                continue;
            }

            $presented = [
                'line_no' => (int) $item['line_no'],
                'sku' => (string) $item['sku'],
                'product_name' => (string) $item['product_name'],
                'provider' => trim((string) $item['provider']),
                'allow_fallback' => $this->dbBool($item['allow_fallback']),
                'status' => (string) $item['status'],
                'price' => [
                    'amount' => Money::fromMinor((int) $item['amount_minor']),
                    'amount_minor' => (int) $item['amount_minor'],
                    'currency' => trim((string) $item['currency']),
                ],
            ];

            if ((string) $item['status'] === 'delivered') {
                $deliveredMinor += (int) $item['amount_minor'];
                $presented['delivery'] = [
                    'code' => $item['delivery_code'],
                    'delivered_at' => $item['delivered_at'],
                ];
            }

            if ((string) $item['status'] === 'refunded') {
                $presented['refund'] = [
                    'amount_minor' => (int) $item['amount_minor'],
                    'reason' => $item['refund_reason'],
                    'refunded_at' => $item['refunded_at'],
                ];
            } elseif ($item['failure_kind'] !== null) {
                $presented['failure'] = ['kind' => $item['failure_kind']];
            }

            $items[] = $presented;
        }

        $moneyStatement = $pdo->prepare(
            "SELECT
                 COALESCE(SUM(amount_minor) FILTER (WHERE entry_type = 'capture'), 0) AS captured_minor,
                 COALESCE(SUM(amount_minor) FILTER (WHERE entry_type = 'refund'), 0) AS refunded_minor
             FROM ledger_entries WHERE order_id = :order_id"
        );
        $moneyStatement->execute(['order_id' => $order['id']]);
        $money = $moneyStatement->fetch();
        $capturedMinor = is_array($money) ? (int) $money['captured_minor'] : 0;
        $refundedMinor = is_array($money) ? (int) $money['refunded_minor'] : 0;
        $outstandingMinor = max(0, $capturedMinor - $deliveredMinor - $refundedMinor);
        $terminal = in_array((string) $order['status'], ['delivered', 'partially_refunded', 'refunded'], true);

        $result = [
            'id' => $order['public_id'],
            'sku' => $order['sku'],
            'product_name' => $order['product_name'],
            'status' => $order['status'],
            'price' => [
                'amount' => Money::fromMinor((int) $order['amount_minor']),
                'amount_minor' => (int) $order['amount_minor'],
                'currency' => trim((string) $order['currency']),
            ],
            'items' => $items,
            'money' => [
                'currency' => trim((string) $order['currency']),
                'paid_minor' => $capturedMinor,
                'delivered_minor' => $deliveredMinor,
                'refunded_minor' => $refundedMinor,
                'outstanding_minor' => $outstandingMinor,
                'equation_holds' => $capturedMinor === $deliveredMinor + $refundedMinor + $outstandingMinor,
                'terminal_equation_holds' => $terminal
                    ? $capturedMinor === $deliveredMinor + $refundedMinor
                    : null,
            ],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
        ];

        if ((string) $order['status'] === 'delivered' && $order['delivery_code'] !== null) {
            $result['delivery'] = [
                'code' => $order['delivery_code'],
                'delivered_at' => $order['delivered_at'],
            ];
        }

        return $result;
    }

    private function dbBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
