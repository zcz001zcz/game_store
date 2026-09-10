<?php

declare(strict_types=1);

namespace GameStore\Domain\Order;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function isPaid(): bool
    {
        return in_array($this, [
            self::Paid,
            self::Delivering,
            self::Delivered,
            self::OutOfStock,
            self::DeliveryFailed,
            self::PartiallyRefunded,
            self::Refunded,
        ], true);
    }

    public function isRecoverableDeliveryState(): bool
    {
        return in_array($this, [self::Paid, self::Delivering, self::OutOfStock, self::DeliveryFailed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Created => in_array($next, [self::Paid, self::PaymentFailed], true),
            self::PaymentFailed => $next === self::Paid,
            self::Paid => $next === self::Delivering,
            self::Delivering => in_array($next, [
                self::Delivered,
                self::OutOfStock,
                self::DeliveryFailed,
                self::PartiallyRefunded,
                self::Refunded,
            ], true),
            self::OutOfStock, self::DeliveryFailed => $next === self::Delivering,
            self::Delivered, self::PartiallyRefunded, self::Refunded => false,
        };
    }
}
