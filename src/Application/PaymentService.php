<?php

declare(strict_types=1);

namespace GameStore\Application;

use DateTimeImmutable;
use Exception;
use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Order\OrderStatus;
use GameStore\Domain\Payment\PaymentEvent;
use GameStore\Infrastructure\Queue\QueueRepository;
use GameStore\Support\Money;
use InvalidArgumentException;
use JsonException;
use PDO;

final class PaymentService
{
	public function __construct(
		private readonly Database $database,
		private readonly QueueRepository $queue,
		private readonly JsonLogger $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{accepted: bool, duplicate: bool, state: string}
	 */
	public function accept(array $payload): array
	{
		$event = $this->parse($payload);

		return $this->database->transaction(function (PDO $pdo) use ($event): array {
			$insert = $pdo->prepare(
				"INSERT INTO payment_events
				 (event_id, order_public_id, status, amount_minor, currency, occurred_at, payload, payload_hash)
				 VALUES
				 (:event_id, :order_id, :status, :amount_minor, :currency, :occurred_at,
				  CAST(:payload AS JSONB), :payload_hash)
				 ON CONFLICT (event_id) DO NOTHING"
			);
			$insert->execute([
				'event_id' => $event->eventId,
				'order_id' => $event->orderId,
				'status' => $event->status,
				'amount_minor' => $event->amountMinor,
				'currency' => $event->currency,
				'occurred_at' => $event->occurredAt->format(DATE_ATOM),
				'payload' => $event->payloadJson,
				'payload_hash' => $event->payloadHash,
			]);

			if ($insert->rowCount() === 0) {
				$existing = $pdo->prepare('SELECT payload_hash FROM payment_events WHERE event_id = :event_id');
				$existing->execute(['event_id' => $event->eventId]);
				$storedHash = $existing->fetchColumn();

				if (is_string($storedHash) && !hash_equals($storedHash, $event->payloadHash)) {
					$this->logger->error('payment_event_id_reused_with_different_payload', [
						'event_id' => $event->eventId,
						'order_id' => $event->orderId,
					]);
				}

				return ['accepted' => true, 'duplicate' => true, 'state' => 'unchanged'];
			}

			$aggregateLock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
			$aggregateLock->execute(['lock_key' => 'order:' . $event->orderId]);

			$order = $this->lockOrder($pdo, $event->orderId);

			if ($order === null) {
				$this->logger->info('payment_event_waiting_for_order', [
					'event_id' => $event->eventId,
					'order_id' => $event->orderId,
				]);

				return ['accepted' => true, 'duplicate' => false, 'state' => 'pending_order'];
			}

			$eventRow = [
				'event_id' => $event->eventId,
				'status' => $event->status,
				'amount_minor' => $event->amountMinor,
				'currency' => $event->currency,
				'occurred_at' => $event->occurredAt->format(DATE_ATOM),
			];
			$state = $this->applyEvent($pdo, $eventRow, $order);

			return ['accepted' => true, 'duplicate' => false, 'state' => $state];
		});
	}

	public function replayPendingForOrder(PDO $pdo, string $orderPublicId): void
	{
		$order = $this->lockOrder($pdo, $orderPublicId);

		if ($order === null) {
			return;
		}

		$events = $pdo->prepare(
			"SELECT event_id, status, amount_minor, currency, occurred_at
			 FROM payment_events
			 WHERE order_public_id = :order_id AND processing_state = 'pending'
			 ORDER BY occurred_at ASC, event_id ASC
			 FOR UPDATE"
		);
		$events->execute(['order_id' => $orderPublicId]);

		while ($event = $events->fetch()) {
			if (is_array($event)) {
				$this->applyEvent($pdo, $event, $order);
			}
		}
	}

	/** @param array<string, mixed> $payload */
	private function parse(array $payload): PaymentEvent
	{
		foreach (['event_id', 'order_id', 'status', 'amount', 'currency', 'created_at'] as $field) {
			if (!array_key_exists($field, $payload)) {
				throw new BadRequestException('Missing required field', ['field' => $field]);
			}
		}

		$eventId = is_string($payload['event_id']) ? trim($payload['event_id']) : '';
		$orderId = is_string($payload['order_id']) ? trim($payload['order_id']) : '';
		$status = is_string($payload['status']) ? strtolower(trim($payload['status'])) : '';
		$currency = is_string($payload['currency']) ? strtoupper(trim($payload['currency'])) : '';

		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{2,119}$/', $eventId)) {
			throw new BadRequestException('Invalid event_id');
		}

		if (!preg_match('/^ord_[A-Za-z0-9_-]{3,70}$/', $orderId)) {
			throw new BadRequestException('Invalid order_id');
		}

		if (!in_array($status, ['paid', 'failed'], true)) {
			throw new BadRequestException('status must be paid or failed');
		}

		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			throw new BadRequestException('currency must be a three-letter ISO code');
		}

		if (!is_int($payload['amount']) && !is_float($payload['amount']) && !is_string($payload['amount'])) {
			throw new BadRequestException('amount must be numeric');
		}

		try {
			$amountMinor = Money::toMinor($payload['amount']);
		} catch (InvalidArgumentException $exception) {
			throw new BadRequestException($exception->getMessage());
		}

		if (!is_string($payload['created_at'])) {
			throw new BadRequestException('created_at must be an RFC 3339 string');
		}

		$createdAt = trim($payload['created_at']);

		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $createdAt)) {
			throw new BadRequestException('created_at must be an RFC 3339 timestamp');
		}

		try {
			$occurredAt = new DateTimeImmutable($createdAt);
		} catch (Exception) {
			throw new BadRequestException('created_at must be an RFC 3339 timestamp');
		}

		$dateErrors = DateTimeImmutable::getLastErrors();

		if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
			throw new BadRequestException('created_at must be a valid RFC 3339 timestamp');
		}

		try {
			$payloadJson = json_encode(
				$payload,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
			);
			$canonicalJson = json_encode(
				$this->canonicalize($payload),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
			);
		} catch (JsonException $exception) {
			throw new BadRequestException('Webhook payload cannot be encoded', ['json_error' => $exception->getMessage()]);
		}

		return new PaymentEvent(
			$eventId,
			$orderId,
			$status,
			$amountMinor,
			$currency,
			$occurredAt,
			$payloadJson,
			hash('sha256', $canonicalJson),
		);
	}

	private function canonicalize(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		if (array_is_list($value)) {
			return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
		}

		ksort($value, SORT_STRING);

		foreach ($value as $key => $item) {
			$value[$key] = $this->canonicalize($item);
		}

		return $value;
	}

	/** @return array<string, mixed>|null */
	private function lockOrder(PDO $pdo, string $publicId): ?array
	{
		$statement = $pdo->prepare('SELECT * FROM orders WHERE public_id = :public_id FOR UPDATE');
		$statement->execute(['public_id' => $publicId]);
		$order = $statement->fetch();

		return is_array($order) ? $order : null;
	}

	/**
	 * @param array<string, mixed> $event
	 * @param array<string, mixed> $order
	 */
	private function applyEvent(PDO $pdo, array $event, array &$order): string
	{
		$eventId = (string) $event['event_id'];
		$amountMatches = (int) $event['amount_minor'] === (int) $order['amount_minor'];
		$currencyMatches = trim((string) $event['currency']) === trim((string) $order['currency']);

		if (!$amountMatches || !$currencyMatches) {
			$this->markEvent($pdo, $eventId, 'invalid', 'amount_or_currency_mismatch');
			$this->logger->warning('payment_event_amount_mismatch', [
				'event_id' => $eventId,
				'order_id' => $order['public_id'],
			]);

			return 'invalid';
		}

		$currentStatus = OrderStatus::from((string) $order['status']);

		if ((string) $event['status'] === 'failed') {
			if ($currentStatus === OrderStatus::Created) {
				$update = $pdo->prepare(
					"UPDATE orders SET status = 'payment_failed', version = version + 1, updated_at = NOW()
					 WHERE id = :id"
				);
				$update->execute(['id' => $order['id']]);
				$order['status'] = OrderStatus::PaymentFailed->value;
				$this->markEvent($pdo, $eventId, 'applied', 'payment_failed');

				return 'applied';
			}

			$this->markEvent($pdo, $eventId, 'ignored', 'paid_state_is_monotonic');

			return 'ignored';
		}

		$transitioned = false;

		if (in_array($currentStatus, [OrderStatus::Created, OrderStatus::PaymentFailed], true)) {
			$update = $pdo->prepare(
				"UPDATE orders SET status = 'paid', payment_confirmed_at = COALESCE(payment_confirmed_at, :occurred_at),
				 version = version + 1, updated_at = NOW() WHERE id = :id"
			);
			$update->execute(['occurred_at' => $event['occurred_at'], 'id' => $order['id']]);
			$order['status'] = OrderStatus::Paid->value;
			$order['payment_confirmed_at'] = $event['occurred_at'];
			$transitioned = true;
		}

		$ledger = $pdo->prepare(
			"INSERT INTO ledger_entries (order_id, event_id, entry_type, amount_minor, currency)
			 VALUES (:order_id, :event_id, 'capture', :amount_minor, :currency)
			 ON CONFLICT (order_id, entry_type) DO NOTHING"
		);
		$ledger->execute([
			'order_id' => $order['id'],
			'event_id' => $eventId,
			'amount_minor' => $order['amount_minor'],
			'currency' => trim((string) $order['currency']),
		]);

		if ((string) $order['status'] !== OrderStatus::Delivered->value) {
			$this->queue->enqueue(
				$pdo,
				'issue_order',
				(string) $order['public_id'],
				'issue-order:' . $order['public_id'],
				['order_id' => $order['public_id']],
			);
		}

		$this->markEvent($pdo, $eventId, $transitioned ? 'applied' : 'ignored', $transitioned ? 'payment_confirmed' : 'already_paid');
		$this->logger->info('payment_event_processed', [
			'event_id' => $eventId,
			'order_id' => $order['public_id'],
			'transitioned' => $transitioned,
		]);

		return $transitioned ? 'applied' : 'ignored';
	}

	private function markEvent(PDO $pdo, string $eventId, string $state, string $note): void
	{
		$statement = $pdo->prepare(
			'UPDATE payment_events SET processing_state = :state, processing_note = :note, processed_at = NOW() '
			. 'WHERE event_id = :event_id'
		);
		$statement->execute(['state' => $state, 'note' => $note, 'event_id' => $eventId]);
	}
}
