<?php

declare(strict_types=1);

namespace GameStore\Support;

use InvalidArgumentException;

final class Money
{
	public static function toMinor(int|float|string $amount): int
	{
		if (is_float($amount)) {
			if (!is_finite($amount)) {
				throw new InvalidArgumentException('Amount must be finite');
			}

			$amount = rtrim(rtrim(sprintf('%.14F', $amount), '0'), '.');
		}

		$normalized = trim((string) $amount);

		if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
			throw new InvalidArgumentException('Amount must be a non-negative number with at most two decimals');
		}

		$major = (int) $matches[1];
		$fraction = str_pad($matches[2] ?? '', 2, '0');

		if ($major > intdiv(PHP_INT_MAX - (int) $fraction, 100)) {
			throw new InvalidArgumentException('Amount is too large');
		}

		return ($major * 100) + (int) $fraction;
	}

	public static function fromMinor(int $minor): string
	{
		if ($minor < 0) {
			throw new InvalidArgumentException('Amount cannot be negative');
		}

		return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
	}
}
