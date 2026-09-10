<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Queue;

use GameStore\Core\Database\Database;
use JsonException;
use PDO;

final class QueueRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(
        PDO $pdo,
        string $type,
        string $aggregateId,
        string $dedupKey,
        array $payload,
        int $maxAttempts = 12,
        int $priority = 100,
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO jobs (type, aggregate_id, dedup_key, payload, max_attempts, priority) '
            . 'VALUES (:type, :aggregate_id, :dedup_key, CAST(:payload AS JSONB), :max_attempts, :priority) '
            . 'ON CONFLICT (dedup_key) DO NOTHING'
        );
        $statement->execute([
            'type' => $type,
            'aggregate_id' => $aggregateId,
            'dedup_key' => $dedupKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'max_attempts' => $maxAttempts,
            'priority' => max(0, min(1000, $priority)),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function reserve(string $workerId, int $staleAfterSeconds): ?array
    {
        return $this->database->transaction(function (PDO $pdo) use ($workerId, $staleAfterSeconds): ?array {
            $select = $pdo->prepare(
                "SELECT * FROM jobs
                 WHERE (status = 'queued' AND available_at <= NOW())
                    OR (status = 'processing' AND locked_at < NOW() - (:stale_after * INTERVAL '1 second'))
                 ORDER BY priority DESC, available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $select->bindValue('stale_after', $staleAfterSeconds, PDO::PARAM_INT);
            $select->execute();
            $job = $select->fetch();

            if (!is_array($job)) {
                return null;
            }

            $update = $pdo->prepare(
                "UPDATE jobs
                 SET status = 'processing', attempts = attempts + 1, locked_at = NOW(),
                     locked_by = :worker_id, updated_at = NOW()
                 WHERE id = :id
                 RETURNING *"
            );
            $update->execute(['worker_id' => $workerId, 'id' => $job['id']]);
            $reserved = $update->fetch();

            if (!is_array($reserved)) {
                return null;
            }

            try {
                $payload = json_decode((string) $reserved['payload'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new \RuntimeException('Stored job payload is invalid JSON', 0, $exception);
            }

            $reserved['payload'] = is_array($payload) ? $payload : [];

            return $reserved;
        });
    }

    public function complete(int $jobId, string $workerId): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE jobs SET status = 'completed', locked_at = NULL, locked_by = NULL,
             last_error = NULL, updated_at = NOW()
             WHERE id = :id AND status = 'processing' AND locked_by = :worker_id"
        );
        $statement->execute(['id' => $jobId, 'worker_id' => $workerId]);
    }

    public function retry(int $jobId, string $workerId, string $error, int $delayMs): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE jobs SET status = 'queued', available_at = NOW() + (:delay_ms * INTERVAL '1 millisecond'),
             locked_at = NULL, locked_by = NULL, last_error = :error, updated_at = NOW()
             WHERE id = :id AND status = 'processing' AND locked_by = :worker_id"
        );
        $statement->bindValue('delay_ms', $delayMs, PDO::PARAM_INT);
        $statement->bindValue('error', substr($error, 0, 4000));
        $statement->bindValue('id', $jobId, PDO::PARAM_INT);
        $statement->bindValue('worker_id', $workerId);
        $statement->execute();
    }

    /** Rate-limit deferral is scheduling, not a failed business attempt. */
    public function defer(int $jobId, string $workerId, string $error, int $delayMs): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE jobs SET status = 'queued', attempts = GREATEST(0, attempts - 1),
             available_at = NOW() + (:delay_ms * INTERVAL '1 millisecond'),
             locked_at = NULL, locked_by = NULL, last_error = :error, updated_at = NOW()
             WHERE id = :id AND status = 'processing' AND locked_by = :worker_id"
        );
        $statement->bindValue('delay_ms', $delayMs, PDO::PARAM_INT);
        $statement->bindValue('error', substr($error, 0, 4000));
        $statement->bindValue('id', $jobId, PDO::PARAM_INT);
        $statement->bindValue('worker_id', $workerId);
        $statement->execute();
    }

    public function markDead(int $jobId, string $workerId, string $error): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE jobs SET status = 'dead', locked_at = NULL, locked_by = NULL,
             last_error = :error, updated_at = NOW()
             WHERE id = :id AND status = 'processing' AND locked_by = :worker_id"
        );
        $statement->execute([
            'error' => substr($error, 0, 4000),
            'id' => $jobId,
            'worker_id' => $workerId,
        ]);
    }

    public function requeueOrder(PDO $pdo, string $orderPublicId): void
    {
        $statement = $pdo->prepare(
            "UPDATE jobs
             SET status = 'queued', attempts = 0, available_at = NOW(), locked_at = NULL,
                 locked_by = NULL, last_error = NULL, updated_at = NOW()
             WHERE dedup_key = :dedup_key AND status IN ('completed', 'dead')"
        );
        $statement->execute(['dedup_key' => 'issue-order:' . $orderPublicId]);

        if ($statement->rowCount() === 0) {
            $this->enqueue(
                $pdo,
                'issue_order',
                $orderPublicId,
                'issue-order:' . $orderPublicId,
                ['order_id' => $orderPublicId],
            );
        }
    }

    /** @return array<string, int> */
    public function progress(): array
    {
        $row = $this->database->connection()->query(
            "SELECT
                 COUNT(*) FILTER (WHERE status = 'queued') AS queued,
                 COUNT(*) FILTER (WHERE status = 'processing') AS processing,
                 COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                 COUNT(*) FILTER (WHERE status = 'dead') AS dead
             FROM jobs WHERE type = 'issue_order'"
        )->fetch();
        $items = $this->database->connection()->query(
            "SELECT
                 COUNT(*) FILTER (WHERE status IN ('pending', 'delivering', 'out_of_stock', 'delivery_failed')) AS waiting_items,
                 COUNT(*) FILTER (WHERE status = 'delivered') AS delivered_items,
                 COUNT(*) FILTER (WHERE status = 'refunded') AS refunded_items
             FROM order_items"
        )->fetch();
        $unpaid = $this->database->connection()->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ('created', 'payment_failed')"
        )->fetchColumn();

        return [
            'queued_orders' => is_array($row) ? (int) $row['queued'] : 0,
            'processing_orders' => is_array($row) ? (int) $row['processing'] : 0,
            'completed_jobs' => is_array($row) ? (int) $row['completed'] : 0,
            'dead_jobs' => is_array($row) ? (int) $row['dead'] : 0,
            'waiting_items' => is_array($items) ? (int) $items['waiting_items'] : 0,
            'delivered_items' => is_array($items) ? (int) $items['delivered_items'] : 0,
            'refunded_items' => is_array($items) ? (int) $items['refunded_items'] : 0,
            'unpaid_orders_not_admitted' => is_numeric($unpaid) ? (int) $unpaid : 0,
        ];
    }
}
