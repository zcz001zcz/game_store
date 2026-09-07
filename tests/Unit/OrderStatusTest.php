<?php

declare(strict_types=1);

namespace GameStore\Tests\Unit;

use GameStore\Domain\Order\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
	public function testHappyPathTransitionsAreAllowed(): void
	{
		self::assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::Paid));
		self::assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Delivering));
		self::assertTrue(OrderStatus::Delivering->canTransitionTo(OrderStatus::Delivered));
	}

	public function testDeliveredOrderIsFinal(): void
	{
		self::assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Delivering));
		self::assertTrue(OrderStatus::Delivered->canTransitionTo(OrderStatus::Delivered));
	}

	public function testFailedDeliveryCanBeRecovered(): void
	{
		self::assertTrue(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivering));
		self::assertTrue(OrderStatus::DeliveryFailed->canTransitionTo(OrderStatus::Delivering));
	}

	public function testPaidStateNeverReturnsToPaymentFailed(): void
	{
		self::assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::PaymentFailed));
	}
}
