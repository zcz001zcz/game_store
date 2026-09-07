<?php

declare(strict_types=1);

namespace GameStore\Domain\Payment;

use DateTimeImmutable;

final readonly class PaymentEvent
{
	public function __construct(
		public string $eventId,
		public string $orderId,
		public string $status,
		public int $amountMinor,
		public string $currency,
		public DateTimeImmutable $occurredAt,
		public string $payloadJson,
		public string $payloadHash,
	) {
	}
}
