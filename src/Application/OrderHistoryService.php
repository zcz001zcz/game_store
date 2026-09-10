<?php

declare(strict_types=1);

namespace GameStore\Application;

use DateTimeImmutable;
use Exception;
use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\NotFoundException;
use JsonException;
use PDO;

final class OrderHistoryService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, mixed> */
    public function stateAt(string $orderPublicId, string $at): array
    {
        $timestamp = $this->timestamp($at, 'at');
        $statement = $this->database->connection()->prepare(
            'SELECT id, event_type, order_status, snapshot, recorded_at '
            . 'FROM order_events WHERE order_public_id = :order_id AND recorded_at <= :at '
            . 'ORDER BY recorded_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['order_id' => $orderPublicId, 'at' => $timestamp]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new NotFoundException('No order state exists at the requested time');
        }

        try {
            $snapshot = json_decode((string) $row['snapshot'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Stored order history is invalid', 0, $exception);
        }

        return [
            'as_of' => $timestamp,
            'event_id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'recorded_at' => (string) $row['recorded_at'],
            'state' => is_array($snapshot) ? $snapshot : [],
        ];
    }

    /** @return array<string, mixed> */
    public function periodSummary(string $from, string $to, ?string $orderPublicId): array
    {
        $fromTimestamp = $this->timestamp($from, 'from');
        $toTimestamp = $this->timestamp($to, 'to');

        if (new DateTimeImmutable($fromTimestamp) > new DateTimeImmutable($toTimestamp)) {
            throw new BadRequestException('from must not be later than to');
        }

        $sql = 'SELECT COUNT(*) AS event_count, COUNT(DISTINCT order_public_id) AS order_count, '
            . 'COALESCE(SUM(captured_delta_minor), 0) AS captured_minor, '
            . 'COALESCE(SUM(delivered_delta_minor), 0) AS delivered_minor, '
            . 'COALESCE(SUM(refunded_delta_minor), 0) AS refunded_minor '
            . 'FROM order_events WHERE recorded_at >= :from AND recorded_at <= :to';
        $parameters = ['from' => $fromTimestamp, 'to' => $toTimestamp];

        if ($orderPublicId !== null) {
            $sql .= ' AND order_public_id = :order_id';
            $parameters['order_id'] = $orderPublicId;
        }

        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new \RuntimeException('Could not calculate history summary');
        }

        $captured = (int) $row['captured_minor'];
        $delivered = (int) $row['delivered_minor'];
        $refunded = (int) $row['refunded_minor'];

        return [
            'from' => $fromTimestamp,
            'to' => $toTimestamp,
            'order_id' => $orderPublicId,
            'event_count' => (int) $row['event_count'],
            'order_count' => (int) $row['order_count'],
            'captured_minor' => $captured,
            'delivered_minor' => $delivered,
            'refunded_minor' => $refunded,
            'unsettled_minor' => $captured - $delivered - $refunded,
            'equation_holds' => $captured === $delivered + $refunded
                + ($captured - $delivered - $refunded),
            'terminal_equation_holds' => $captured === $delivered + $refunded,
        ];
    }

    private function timestamp(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new BadRequestException($field . ' is required');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new BadRequestException($field . ' must be an RFC 3339 timestamp');
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception) {
            throw new BadRequestException($field . ' must be an RFC 3339 timestamp');
        }

        return $date->format('Y-m-d\\TH:i:s.uP');
    }
}
