<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Infrastructure\Queue\QueueRepository;
use PDO;

final class ReconciliationService
{
    public function __construct(
        private readonly Database $database,
        private readonly QueueRepository $queue,
        private readonly OrderEventStore $events,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $pdo = $this->database->connection();

        $paidNotDelivered = $pdo->prepare(
            "SELECT o.public_id AS order_id, o.status, o.updated_at
             FROM orders o
             WHERE o.status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
               AND EXISTS (SELECT 1 FROM ledger_entries l WHERE l.order_id = o.id AND l.entry_type = 'capture')
             ORDER BY o.updated_at ASC LIMIT :limit"
        );
        $paidNotDelivered->bindValue('limit', $limit, PDO::PARAM_INT);
        $paidNotDelivered->execute();

        $deliveredWithoutPayment = $pdo->prepare(
            "SELECT o.public_id AS order_id, o.status, o.updated_at
             FROM orders o
             WHERE o.status = 'delivered'
               AND NOT EXISTS (SELECT 1 FROM ledger_entries l WHERE l.order_id = o.id AND l.entry_type = 'capture')
             ORDER BY o.updated_at ASC LIMIT :limit"
        );
        $deliveredWithoutPayment->bindValue('limit', $limit, PDO::PARAM_INT);
        $deliveredWithoutPayment->execute();

        $ledgerMismatch = $pdo->prepare(
            "SELECT o.public_id AS order_id, o.amount_minor AS expected_minor,
                    l.amount_minor AS captured_minor, o.currency AS expected_currency,
                    l.currency AS captured_currency
             FROM orders o
             JOIN ledger_entries l ON l.order_id = o.id AND l.entry_type = 'capture'
             WHERE o.amount_minor <> l.amount_minor OR o.currency <> l.currency
             ORDER BY o.created_at ASC LIMIT :limit"
        );
        $ledgerMismatch->bindValue('limit', $limit, PDO::PARAM_INT);
        $ledgerMismatch->execute();

        $pendingEvents = $pdo->prepare(
            "SELECT pe.event_id, pe.order_public_id AS order_id, pe.received_at
             FROM payment_events pe
             WHERE pe.processing_state = 'pending'
             ORDER BY pe.received_at ASC LIMIT :limit"
        );
        $pendingEvents->bindValue('limit', $limit, PDO::PARAM_INT);
        $pendingEvents->execute();

        $moneyInvariant = $pdo->prepare(
            "WITH totals AS (
                 SELECT o.public_id AS order_id, o.status, o.amount_minor,
                        COALESCE(SUM(l.amount_minor) FILTER (WHERE l.entry_type = 'capture'), 0) AS captured_minor,
                        COALESCE(SUM(l.amount_minor) FILTER (WHERE l.entry_type = 'refund'), 0) AS refunded_minor,
                        COALESCE((SELECT SUM(oi.amount_minor) FROM order_items oi
                                  WHERE oi.order_id = o.id AND oi.status = 'delivered'), 0) AS delivered_minor
                 FROM orders o LEFT JOIN ledger_entries l ON l.order_id = o.id
                 GROUP BY o.id
             )
             SELECT * FROM totals
             WHERE status IN ('delivered', 'partially_refunded', 'refunded')
               AND captured_minor <> delivered_minor + refunded_minor
             ORDER BY order_id LIMIT :limit"
        );
        $moneyInvariant->bindValue('limit', $limit, PDO::PARAM_INT);
        $moneyInvariant->execute();

        $providerDiscrepancies = $pdo->prepare(
            "SELECT o.public_id AS order_id, oi.line_no, pd.provider, pd.request_id,
                    pd.kind, pd.reported_code, pd.detected_at
             FROM provider_discrepancies pd
             JOIN orders o ON o.id = pd.order_id
             JOIN order_items oi ON oi.id = pd.order_item_id
             WHERE pd.status = 'open'
             ORDER BY pd.detected_at ASC LIMIT :limit"
        );
        $providerDiscrepancies->bindValue('limit', $limit, PDO::PARAM_INT);
        $providerDiscrepancies->execute();

        $providerCodeCollisions = $pdo->prepare(
            "SELECT provider, code, COUNT(*) AS issue_count,
                    array_agg(DISTINCT order_public_id ORDER BY order_public_id) AS order_ids
             FROM provider_issues GROUP BY provider, code HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC, provider, code LIMIT :limit"
        );
        $providerCodeCollisions->bindValue('limit', $limit, PDO::PARAM_INT);
        $providerCodeCollisions->execute();

        return [
            'paid_not_delivered' => $paidNotDelivered->fetchAll(),
            'delivered_without_payment' => $deliveredWithoutPayment->fetchAll(),
            'ledger_mismatches' => $ledgerMismatch->fetchAll(),
            'pending_payment_events' => $pendingEvents->fetchAll(),
            'money_invariant_violations' => $moneyInvariant->fetchAll(),
            'open_provider_discrepancies' => $providerDiscrepancies->fetchAll(),
            'provider_code_collisions' => $providerCodeCollisions->fetchAll(),
        ];
    }

    /** @return list<string> */
    public function recoverStuck(int $limit, int $olderThanSeconds, ?string $onlyOrderId = null): array
    {
        $limit = max(1, min($limit, 100));
        $olderThanSeconds = max(1, $olderThanSeconds);

        return $this->database->transaction(function (PDO $pdo) use ($limit, $olderThanSeconds, $onlyOrderId): array {
            $sql = "SELECT id, public_id, status FROM orders
                    WHERE status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
                      AND EXISTS (
                          SELECT 1 FROM ledger_entries l
                          WHERE l.order_id = orders.id AND l.entry_type = 'capture'
                      )";

            if ($onlyOrderId !== null) {
                $sql .= ' AND public_id = :order_id';
            } else {
                $sql .= " AND updated_at < NOW() - (:older_than * INTERVAL '1 second')";
            }

            $sql .= ' ORDER BY updated_at ASC LIMIT :limit FOR UPDATE SKIP LOCKED';
            $statement = $pdo->prepare($sql);

            if ($onlyOrderId !== null) {
                $statement->bindValue('order_id', $onlyOrderId);
            } else {
                $statement->bindValue('older_than', $olderThanSeconds, PDO::PARAM_INT);
            }

            $statement->bindValue('limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $recovered = [];
            $orders = $statement->fetchAll();

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }

                if (in_array((string) $order['status'], ['out_of_stock', 'delivery_failed'], true)) {
                    $resetAttempts = $pdo->prepare(
                        "UPDATE delivery_attempts SET status = 'pending', failure_kind = NULL,
                         last_error = NULL, updated_at = NOW()
                         WHERE order_id = :order_id AND status = 'definitive_failed'
                           AND NOT EXISTS (
                               SELECT 1 FROM delivery_attempts uncertain
                               WHERE uncertain.order_id = :order_id_for_uncertain
                                 AND (
                                     uncertain.status = 'uncertain'
                                     OR (uncertain.status = 'pending' AND uncertain.attempt_count > 0)
                                 )
                           )"
                    );
                    $resetAttempts->execute([
                        'order_id' => $order['id'],
                        'order_id_for_uncertain' => $order['id'],
                    ]);

                    $resetItems = $pdo->prepare(
                        "UPDATE order_items SET status = 'pending', failure_kind = NULL,
                         version = version + 1, updated_at = NOW()
                         WHERE order_id = :order_id AND refund_on_failure = FALSE
                           AND status IN ('out_of_stock', 'delivery_failed')"
                    );
                    $resetItems->execute(['order_id' => $order['id']]);
                }

                $touch = $pdo->prepare('UPDATE orders SET updated_at = NOW() WHERE id = :id');
                $touch->execute(['id' => $order['id']]);
                $this->queue->requeueOrder($pdo, (string) $order['public_id']);
                $this->events->append(
                    $pdo,
                    (string) $order['id'],
                    'order.requeued',
                    'order-requeued:' . $order['id'] . ':' . hash('sha256', (string) microtime(true)),
                );
                $recovered[] = (string) $order['public_id'];
            }

            return $recovered;
        });
    }
}
