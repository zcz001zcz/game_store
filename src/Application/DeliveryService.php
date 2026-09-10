<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Order\OrderStatus;
use GameStore\Domain\Provider\ProviderAuditResult;
use GameStore\Domain\Provider\RetryableDeliveryException;
use GameStore\Infrastructure\Provider\ProviderHttpClient;
use GameStore\Infrastructure\Provider\ProviderRateLimiter;
use GameStore\Support\Env;
use PDO;
use PDOException;

final class DeliveryService
{
    private const MAX_REPAIR_GENERATIONS = 3;

    public function __construct(
        private readonly Database $database,
        private readonly ProviderHttpClient $providers,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly OrderEventStore $events,
        private readonly JsonLogger $logger,
    ) {
    }

    public function deliver(string $orderPublicId): void
    {
        $order = $this->prepareOrder($orderPublicId);

        if ($order === null) {
            return;
        }

        $statement = $this->database->connection()->prepare(
            "SELECT * FROM order_items
             WHERE order_id = :order_id AND status NOT IN ('delivered', 'refunded')
             ORDER BY line_no ASC"
        );
        $statement->execute(['order_id' => $order['id']]);
        $items = $statement->fetchAll();

        foreach ($items as $item) {
            if (is_array($item)) {
                $this->deliverItem($order, $item);
            }
        }

        $this->database->transaction(function (PDO $pdo) use ($order): void {
            $this->updateAggregate($pdo, (string) $order['id']);
        });
    }

    public function markExhausted(string $orderPublicId, string $reason): void
    {
        $this->database->transaction(function (PDO $pdo) use ($orderPublicId): void {
            $orderStatement = $pdo->prepare('SELECT * FROM orders WHERE public_id = :id FOR UPDATE');
            $orderStatement->execute(['id' => $orderPublicId]);
            $order = $orderStatement->fetch();

            if (!is_array($order)) {
                return;
            }

            $items = $pdo->prepare(
                "SELECT * FROM order_items
                 WHERE order_id = :order_id AND status NOT IN ('delivered', 'refunded')
                 FOR UPDATE"
            );
            $items->execute(['order_id' => $order['id']]);
            $pendingItems = $items->fetchAll();

            foreach ($pendingItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ($this->dbBool($item['refund_on_failure'])) {
                    $this->refundItem($pdo, $order, $item, 'retry_budget_exhausted');
                } else {
                    $update = $pdo->prepare(
                        "UPDATE order_items SET status = 'delivery_failed',
                         failure_kind = 'retry_budget_exhausted', version = version + 1, updated_at = NOW()
                         WHERE id = :id"
                    );
                    $update->execute(['id' => $item['id']]);
                }
            }

            $this->updateAggregate($pdo, (string) $order['id']);
            $this->events->append(
                $pdo,
                (string) $order['id'],
                'order.delivery_exhausted',
                'order-delivery-exhausted:' . $order['id'] . ':' . $order['version'],
            );
        });

        $this->logger->error('order_delivery_retries_exhausted', [
            'order_id' => $orderPublicId,
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function deliverItem(array $order, array $item): void
    {
        $this->markItemDelivering($order, $item);
        $primary = trim((string) $item['provider']);
        $providers = [$primary];

        if ($this->dbBool($item['allow_fallback'])) {
            $providers[] = $primary === 'A' ? 'B' : 'A';
        }

        $failureKinds = [];

        foreach ($providers as $provider) {
            for ($generation = 1; $generation <= self::MAX_REPAIR_GENERATIONS; $generation++) {
                $attempt = $this->getOrCreateAttempt($order, $item, $provider, $generation);
                $attemptStatus = (string) $attempt['status'];
                $failureKind = (string) ($attempt['failure_kind'] ?? '');

                if ($attemptStatus === 'succeeded') {
                    $code = (string) ($attempt['code'] ?? '');

                    if ($code === '') {
                        throw new RetryableDeliveryException('Succeeded attempt has no code');
                    }

                    if ($this->finalize($order, $item, $attempt, $code)) {
                        return;
                    }

                    $this->rejectClaim($order, $item, $attempt, 'duplicate_code', $code, (string) $item['sku']);
                    continue;
                }

                if ($attemptStatus === 'definitive_failed') {
                    if ($failureKind === 'invalid_claim' && $generation < self::MAX_REPAIR_GENERATIONS) {
                        continue;
                    }

                    $failureKinds[] = $failureKind === '' ? 'unavailable' : $failureKind;
                    break;
                }

                $this->rateLimiter->acquire($provider, (string) $attempt['request_id']);
                $this->markAttemptStarted((int) $attempt['id']);
                $response = $this->providers->issue(
                    $provider,
                    (string) $attempt['request_id'],
                    (string) $item['sku'],
                    (string) $order['public_id'],
                );

                // A transport timeout is deliberately retried with the same request_id.
                // This preserves stage-1 behaviour and lets an idempotent replay recover
                // without ever falling back while the outcome is unknown.
                if ($response->isUncertain() && $response->reason === 'timeout') {
                    $this->markAttemptUncertain((int) $attempt['id'], 'timeout');
                    throw new RetryableDeliveryException('Provider result is uncertain: timeout');
                }

                $audit = $this->providers->audit($provider, (string) $attempt['request_id']);

                if ($audit->outcome === 'uncertain') {
                    $this->markAttemptUncertain((int) $attempt['id'], (string) $audit->reason);
                    throw new RetryableDeliveryException('Provider audit is unavailable: ' . $audit->reason);
                }

                if ($audit->isFound()) {
                    $problem = $this->validateAudit($order, $item, $response->code, $audit);

                    if ($problem !== null) {
                        $this->rejectClaim(
                            $order,
                            $item,
                            $attempt,
                            $problem,
                            (string) $audit->code,
                            (string) $audit->actualSku,
                        );
                        continue;
                    }

                    if ($response->reason === 'crash_worker_after_audit' && $this->canInjectTestFault()) {
                        $this->logger->warning('simulated_worker_crash_after_provider_issue', [
                            'order_id' => $order['public_id'],
                            'item_id' => $item['id'],
                            'request_id' => $attempt['request_id'],
                        ]);
                        exit(70);
                    }

                    try {
                        if ($this->finalize($order, $item, $attempt, (string) $audit->code)) {
                            $this->logger->info('order_item_delivered', [
                                'order_id' => $order['public_id'],
                                'line_no' => $item['line_no'],
                                'provider' => $provider,
                                'request_id' => $attempt['request_id'],
                            ]);

                            return;
                        }
                    } catch (PDOException $exception) {
                        if ((string) $exception->getCode() !== '23505') {
                            throw $exception;
                        }
                    }

                    $this->rejectClaim(
                        $order,
                        $item,
                        $attempt,
                        'duplicate_code',
                        (string) $audit->code,
                        (string) $audit->actualSku,
                    );
                    continue;
                }

                if ($response->isSuccess()) {
                    $this->rejectClaim(
                        $order,
                        $item,
                        $attempt,
                        'missing_from_provider_audit',
                        (string) $response->code,
                        (string) $item['sku'],
                    );
                    continue;
                }

                $kind = $response->outcome === 'out_of_stock' ? 'out_of_stock' : 'unavailable';
                $this->markAttemptDefinitiveFailure((int) $attempt['id'], $kind, (string) $response->reason);
                $failureKinds[] = $kind;
                break;
            }
        }

        $finalKind = $failureKinds !== []
            && count(array_unique($failureKinds)) === 1
            && $failureKinds[0] === 'out_of_stock'
            ? 'out_of_stock'
            : 'delivery_failed';
        $this->completeFailure($order, $item, $finalKind);
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

            if (in_array($status, [OrderStatus::Delivered, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)) {
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
                $this->events->append(
                    $pdo,
                    (string) $order['id'],
                    'order.delivery_started',
                    'order-delivery-started:' . $order['id'] . ':' . $order['version'],
                );
            }

            return $order;
        });
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function markItemDelivering(array $order, array $item): void
    {
        $this->database->transaction(function (PDO $pdo) use ($order, $item): void {
            $update = $pdo->prepare(
                "UPDATE order_items SET status = 'delivering', failure_kind = NULL,
                 version = version + 1, updated_at = NOW()
                 WHERE id = :id AND status NOT IN ('delivered', 'refunded', 'delivering')"
            );
            $update->execute(['id' => $item['id']]);

            if ($update->rowCount() === 1) {
                $this->events->append(
                    $pdo,
                    (string) $order['id'],
                    'item.delivery_started',
                    'item-delivery-started:' . $item['id'] . ':' . $item['version'],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function getOrCreateAttempt(array $order, array $item, string $provider, int $generation): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($order, $item, $provider, $generation): array {
            $requestId = 'req_' . strtolower($provider) . '_' . substr(
                hash('sha256', $provider . ':' . $item['id'] . ':' . $generation),
                0,
                36,
            );
            $insert = $pdo->prepare(
                'INSERT INTO delivery_attempts (order_id, order_item_id, provider, generation, request_id) '
                . 'VALUES (:order_id, :item_id, :provider, :generation, :request_id) '
                . 'ON CONFLICT (order_item_id, provider, generation) DO NOTHING'
            );
            $insert->execute([
                'order_id' => $order['id'],
                'item_id' => $item['id'],
                'provider' => $provider,
                'generation' => $generation,
                'request_id' => $requestId,
            ]);
            $select = $pdo->prepare(
                'SELECT * FROM delivery_attempts '
                . 'WHERE order_item_id = :item_id AND provider = :provider AND generation = :generation '
                . 'FOR UPDATE'
            );
            $select->execute(['item_id' => $item['id'], 'provider' => $provider, 'generation' => $generation]);
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

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function validateAudit(
        array $order,
        array $item,
        ?string $reportedCode,
        ProviderAuditResult $audit,
    ): ?string {
        if ($audit->orderId !== (string) $order['public_id']) {
            return 'foreign_order';
        }

        if ($audit->requestedSku !== (string) $item['sku'] || $audit->actualSku !== (string) $item['sku']) {
            return 'foreign_sku';
        }

        if ($reportedCode !== null && !hash_equals($reportedCode, (string) $audit->code)) {
            return 'response_audit_mismatch';
        }

        if ((string) $audit->code === '' || strlen((string) $audit->code) > 100) {
            return 'invalid_code_format';
        }

        $statement = $this->database->connection()->prepare(
            'SELECT 1 FROM quarantined_codes WHERE code = :quarantine_code '
            . 'UNION ALL '
            . 'SELECT 1 FROM fulfillments WHERE code = :fulfillment_code AND order_item_id <> :item_id LIMIT 1'
        );
        $statement->execute([
            'quarantine_code' => $audit->code,
            'fulfillment_code' => $audit->code,
            'item_id' => $item['id'],
        ]);

        return $statement->fetchColumn() === false ? null : 'duplicate_code';
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $attempt
     */
    private function rejectClaim(
        array $order,
        array $item,
        array $attempt,
        string $kind,
        string $code,
        string $actualSku,
    ): void {
        $code = substr($code, 0, 100);
        $actualSku = substr($actualSku, 0, 100);
        $this->database->transaction(function (PDO $pdo) use ($order, $item, $attempt, $kind, $code, $actualSku): void {
            $update = $pdo->prepare(
                "UPDATE delivery_attempts SET status = 'definitive_failed', failure_kind = 'invalid_claim',
                 code = :code, last_error = :kind, updated_at = NOW() WHERE id = :id"
            );
            $update->execute(['code' => $code === '' ? null : $code, 'kind' => $kind, 'id' => $attempt['id']]);
            $insert = $pdo->prepare(
                'INSERT INTO provider_discrepancies '
                . '(order_id, order_item_id, delivery_attempt_id, provider, request_id, kind, '
                . 'reported_code, expected_sku, actual_sku) '
                . 'VALUES (:order_id, :item_id, :attempt_id, :provider, :request_id, :kind, '
                . ':code, :expected_sku, :actual_sku) '
                . 'ON CONFLICT (request_id, kind) DO NOTHING'
            );
            $insert->execute([
                'order_id' => $order['id'],
                'item_id' => $item['id'],
                'attempt_id' => $attempt['id'],
                'provider' => $attempt['provider'],
                'request_id' => $attempt['request_id'],
                'kind' => $kind,
                'code' => $code === '' ? null : $code,
                'expected_sku' => $item['sku'],
                'actual_sku' => $actualSku === '' ? null : $actualSku,
            ]);

            if ($code !== '') {
                $quarantine = $pdo->prepare(
                    'INSERT INTO quarantined_codes (provider, code, reason, first_request_id) '
                    . 'VALUES (:provider, :code, :reason, :request_id) '
                    . 'ON CONFLICT (provider, code) DO NOTHING'
                );
                $quarantine->execute([
                    'provider' => $attempt['provider'],
                    'code' => $code,
                    'reason' => $kind,
                    'request_id' => $attempt['request_id'],
                ]);
            }

            $this->events->append(
                $pdo,
                (string) $order['id'],
                'provider.discrepancy_detected',
                'provider-discrepancy:' . $attempt['request_id'] . ':' . $kind,
            );
        });
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @param array<string, mixed> $attempt
     */
    private function finalize(array $order, array $item, array $attempt, string $code): bool
    {
        if ($code === '') {
            throw new RetryableDeliveryException('Provider returned an empty code');
        }

        return $this->database->transaction(function (PDO $pdo) use ($order, $item, $attempt, $code): bool {
            $lock = $pdo->prepare('SELECT status, delivery_code FROM order_items WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $item['id']]);
            $current = $lock->fetch();

            if (!is_array($current)) {
                throw new \RuntimeException('Order item disappeared during delivery');
            }

            if ((string) $current['status'] === 'delivered') {
                return hash_equals((string) $current['delivery_code'], $code);
            }

            if ((string) $current['status'] === 'refunded') {
                return false;
            }

            $duplicate = $pdo->prepare(
                'SELECT order_item_id FROM fulfillments WHERE code = :code FOR SHARE'
            );
            $duplicate->execute(['code' => $code]);
            $owner = $duplicate->fetchColumn();

            if ($owner !== false && (string) $owner !== (string) $item['id']) {
                return false;
            }

            $attemptUpdate = $pdo->prepare(
                "UPDATE delivery_attempts SET status = 'succeeded', code = :code,
                 failure_kind = NULL, last_error = NULL, updated_at = NOW() WHERE id = :id"
            );
            $attemptUpdate->execute(['code' => $code, 'id' => $attempt['id']]);
            $fulfillment = $pdo->prepare(
                'INSERT INTO fulfillments '
                . '(order_id, order_item_id, delivery_attempt_id, provider, request_id, code) '
                . 'VALUES (:order_id, :item_id, :attempt_id, :provider, :request_id, :code)'
            );
            $fulfillment->execute([
                'order_id' => $order['id'],
                'item_id' => $item['id'],
                'attempt_id' => $attempt['id'],
                'provider' => trim((string) $attempt['provider']),
                'request_id' => $attempt['request_id'],
                'code' => $code,
            ]);
            $update = $pdo->prepare(
                "UPDATE order_items SET status = 'delivered', delivery_code = :code, delivered_at = NOW(),
                 failure_kind = NULL, version = version + 1, updated_at = NOW() WHERE id = :id"
            );
            $update->execute(['code' => $code, 'id' => $item['id']]);
            $this->updateAggregate($pdo, (string) $order['id']);
            $resolve = $pdo->prepare(
                "UPDATE provider_discrepancies SET status = 'resolved',
                 resolution = 'valid replacement delivered', resolved_at = NOW()
                 WHERE order_item_id = :item_id AND status = 'open'"
            );
            $resolve->execute(['item_id' => $item['id']]);
            $this->events->append(
                $pdo,
                (string) $order['id'],
                'item.delivered',
                'item-delivered:' . $item['id'],
                deliveredDeltaMinor: (int) $item['amount_minor'],
            );

            return true;
        });
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function completeFailure(array $order, array $item, string $kind): void
    {
        $this->database->transaction(function (PDO $pdo) use ($order, $item, $kind): void {
            $lock = $pdo->prepare('SELECT * FROM order_items WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $item['id']]);
            $current = $lock->fetch();

            if (!is_array($current) || in_array((string) $current['status'], ['delivered', 'refunded'], true)) {
                return;
            }

            if ($this->dbBool($current['refund_on_failure'])) {
                $this->refundItem($pdo, $order, $current, $kind);
            } else {
                $status = $kind === 'out_of_stock' ? 'out_of_stock' : 'delivery_failed';
                $update = $pdo->prepare(
                    'UPDATE order_items SET status = :status, failure_kind = :kind, '
                    . 'version = version + 1, updated_at = NOW() WHERE id = :id'
                );
                $update->execute(['status' => $status, 'kind' => $kind, 'id' => $current['id']]);
                $this->updateAggregate($pdo, (string) $order['id']);
                $this->events->append(
                    $pdo,
                    (string) $order['id'],
                    'item.delivery_failed',
                    'item-failed:' . $current['id'] . ':' . $current['version'],
                );
            }
        });
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function refundItem(PDO $pdo, array $order, array $item, string $reason): void
    {
        $idempotencyKey = 'refund:item:' . $item['id'];
        $refund = $pdo->prepare(
            "INSERT INTO refunds
             (order_id, order_item_id, idempotency_key, amount_minor, currency, reason)
             VALUES (:order_id, :item_id, :idempotency_key, :amount_minor, :currency, :reason)
             ON CONFLICT (order_item_id) DO NOTHING"
        );
        $refund->execute([
            'order_id' => $order['id'],
            'item_id' => $item['id'],
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $item['amount_minor'],
            'currency' => trim((string) $item['currency']),
            'reason' => $reason,
        ]);
        $created = $refund->rowCount() === 1;
        $ledger = $pdo->prepare(
            "INSERT INTO ledger_entries
             (order_id, order_item_id, event_id, entry_type, amount_minor, currency, idempotency_key)
             VALUES (:order_id, :item_id, NULL, 'refund', :amount_minor, :currency, :idempotency_key)
             ON CONFLICT (idempotency_key) DO NOTHING"
        );
        $ledger->execute([
            'order_id' => $order['id'],
            'item_id' => $item['id'],
            'amount_minor' => $item['amount_minor'],
            'currency' => trim((string) $item['currency']),
            'idempotency_key' => $idempotencyKey,
        ]);
        $update = $pdo->prepare(
            "UPDATE order_items SET status = 'refunded', failure_kind = :reason,
             version = version + 1, updated_at = NOW() WHERE id = :id AND status <> 'delivered'"
        );
        $update->execute(['reason' => $reason, 'id' => $item['id']]);
        $this->updateAggregate($pdo, (string) $order['id']);

        if ($created) {
            $this->events->append(
                $pdo,
                (string) $order['id'],
                'item.refunded',
                $idempotencyKey,
                refundedDeltaMinor: (int) $item['amount_minor'],
            );
        }
    }

    private function updateAggregate(PDO $pdo, string $orderId): void
    {
        $statement = $pdo->prepare(
            "SELECT
                 COUNT(*) AS total,
                 COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                 COUNT(*) FILTER (WHERE status = 'refunded') AS refunded,
                 COUNT(*) FILTER (WHERE status = 'out_of_stock') AS out_of_stock,
                 COUNT(*) FILTER (WHERE status = 'delivery_failed') AS delivery_failed,
                 MIN(delivery_code) FILTER (WHERE status = 'delivered') AS first_code,
                 MAX(delivered_at) FILTER (WHERE status = 'delivered') AS delivered_at
             FROM order_items WHERE order_id = :order_id"
        );
        $statement->execute(['order_id' => $orderId]);
        $counts = $statement->fetch();

        if (!is_array($counts)) {
            throw new \LogicException('Order item totals could not be calculated');
        }

        $total = (int) $counts['total'];
        $delivered = (int) $counts['delivered'];
        $refunded = (int) $counts['refunded'];

        if ($total > 0 && $delivered === $total) {
            $status = 'delivered';
        } elseif ($total > 0 && $refunded === $total) {
            $status = 'refunded';
        } elseif ($total > 0 && $delivered + $refunded === $total) {
            $status = 'partially_refunded';
        } elseif ((int) $counts['delivery_failed'] > 0) {
            $status = 'delivery_failed';
        } elseif ((int) $counts['out_of_stock'] > 0) {
            $status = 'out_of_stock';
        } else {
            $status = 'delivering';
        }

        $update = $pdo->prepare(
            'UPDATE orders SET status = :status, '
            . 'delivery_code = CASE WHEN :status_for_code = \'delivered\' THEN :code ELSE delivery_code END, '
            . 'delivered_at = CASE WHEN :status_for_time = \'delivered\' THEN :delivered_at ELSE delivered_at END, '
            . 'version = version + 1, updated_at = NOW() WHERE id = :id AND status IS DISTINCT FROM :status_compare'
        );
        $update->execute([
            'status' => $status,
            'status_for_code' => $status,
            'code' => $counts['first_code'],
            'status_for_time' => $status,
            'delivered_at' => $counts['delivered_at'],
            'id' => $orderId,
            'status_compare' => $status,
        ]);
    }

    private function dbBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function canInjectTestFault(): bool
    {
        return in_array(strtolower(Env::string('APP_ENV', 'dev')), [
            'dev',
            'development',
            'local',
            'test',
            'testing',
        ], true);
    }
}
