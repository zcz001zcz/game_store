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

		return [
			'paid_not_delivered' => $paidNotDelivered->fetchAll(),
			'delivered_without_payment' => $deliveredWithoutPayment->fetchAll(),
			'ledger_mismatches' => $ledgerMismatch->fetchAll(),
			'pending_payment_events' => $pendingEvents->fetchAll(),
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

			while ($order = $statement->fetch()) {
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
				}

				$touch = $pdo->prepare('UPDATE orders SET updated_at = NOW() WHERE id = :id');
				$touch->execute(['id' => $order['id']]);
				$this->queue->requeueOrder($pdo, (string) $order['public_id']);
				$recovered[] = (string) $order['public_id'];
			}

			return $recovered;
		});
	}
}
