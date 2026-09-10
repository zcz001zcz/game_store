<?php

declare(strict_types=1);

namespace GameStore\Tests\Unit;

use GameStore\Infrastructure\Queue\Backoff;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    public function testUsesExponentialDelayWithBoundedJitter(): void
    {
        $delay = Backoff::milliseconds(3, 500);

        self::assertGreaterThanOrEqual(2_000, $delay);
        self::assertLessThanOrEqual(2_400, $delay);
    }

    public function testHonorsCap(): void
    {
        self::assertSame(1_000, Backoff::milliseconds(20, 500, 1_000));
    }
}
