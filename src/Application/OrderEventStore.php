<?php

declare(strict_types=1);

namespace GameStore\Application;

use JsonException;
use PDO;

/** Writes immutable, self-contained snapshots inside the caller's transaction. */
final class OrderEventStore
{
    public function append(
        PDO $pdo,
        string $orderId,
        string $eventType,
        string $idempotencyKey,
        int $capturedDeltaMinor = 0,
        int $deliveredDeltaMinor = 0,
        int $refundedDeltaMinor = 0,
    ): void {
        $orderStatement = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $orderStatement->execute(['id' => $orderId]);
        $order = $orderStatement->fetch();

        if (!is_array($order)) {
            throw new \LogicException('Cannot record history for a missing order');
        }

        $itemsStatement = $pdo->prepare(
            'SELECT line_no, sku, product_name, amount_minor, currency, provider, allow_fallback, '
            . 'status, delivery_code, delivered_at, failure_kind '
            . 'FROM order_items WHERE order_id = :order_id ORDER BY line_no ASC'
        );
        $itemsStatement->execute(['order_id' => $orderId]);
        $items = $itemsStatement->fetchAll();

        $moneyStatement = $pdo->prepare(
            "SELECT
                 COALESCE(SUM(amount_minor) FILTER (WHERE entry_type = 'capture'), 0) AS captured_minor,
                 COALESCE(SUM(amount_minor) FILTER (WHERE entry_type = 'refund'), 0) AS refunded_minor
             FROM ledger_entries WHERE order_id = :order_id"
        );
        $moneyStatement->execute(['order_id' => $orderId]);
        $money = $moneyStatement->fetch();
        $captured = is_array($money) ? (int) $money['captured_minor'] : 0;
        $refunded = is_array($money) ? (int) $money['refunded_minor'] : 0;
        $delivered = 0;
        $presentedItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string) $item['status'] === 'delivered') {
                $delivered += (int) $item['amount_minor'];
            }

            $presentedItems[] = [
                'line_no' => (int) $item['line_no'],
                'sku' => (string) $item['sku'],
                'product_name' => (string) $item['product_name'],
                'amount_minor' => (int) $item['amount_minor'],
                'currency' => trim((string) $item['currency']),
                'provider' => trim((string) $item['provider']),
                'allow_fallback' => $item['allow_fallback'] === true
                    || $item['allow_fallback'] === 1
                    || $item['allow_fallback'] === '1'
                    || $item['allow_fallback'] === 't',
                'status' => (string) $item['status'],
                'delivery_code' => $item['delivery_code'],
                'delivered_at' => $item['delivered_at'],
                'failure_kind' => $item['failure_kind'],
            ];
        }

        $outstanding = max(0, $captured - $delivered - $refunded);
        $snapshot = [
            'id' => (string) $order['public_id'],
            'status' => (string) $order['status'],
            'currency' => trim((string) $order['currency']),
            'total_minor' => (int) $order['amount_minor'],
            'money' => [
                'captured_minor' => $captured,
                'delivered_minor' => $delivered,
                'refunded_minor' => $refunded,
                'outstanding_minor' => $outstanding,
                'equation_holds' => $captured === $delivered + $refunded + $outstanding,
            ],
            'items' => $presentedItems,
        ];

        try {
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Cannot encode order history snapshot', 0, $exception);
        }

        $insert = $pdo->prepare(
            'INSERT INTO order_events '
            . '(order_id, order_public_id, event_type, idempotency_key, order_status, '
            . 'captured_delta_minor, delivered_delta_minor, refunded_delta_minor, snapshot) '
            . 'VALUES (:order_id, :public_id, :event_type, :idempotency_key, :order_status, '
            . ':captured_delta, :delivered_delta, :refunded_delta, CAST(:snapshot AS JSONB)) '
            . 'ON CONFLICT (idempotency_key) DO NOTHING'
        );
        $insert->execute([
            'order_id' => $orderId,
            'public_id' => $order['public_id'],
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'order_status' => $order['status'],
            'captured_delta' => $capturedDeltaMinor,
            'delivered_delta' => $deliveredDeltaMinor,
            'refunded_delta' => $refundedDeltaMinor,
            'snapshot' => $json,
        ]);
    }
}
