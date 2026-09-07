<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Queue;

final class Backoff
{
	public static function milliseconds(int $attempt, int $baseMs, int $capMs = 30_000): int
	{
		$attempt = max(1, $attempt);
		$capMs = max(1, $capMs);
		$baseMs = min($capMs, max(1, $baseMs));
		$exponent = min(20, $attempt - 1);
		$delay = (int) min($capMs, $baseMs * (2 ** $exponent));
		$jitterLimit = max(1, (int) floor($delay * 0.2));

		return min($capMs, $delay + random_int(0, $jitterLimit));
	}
}
