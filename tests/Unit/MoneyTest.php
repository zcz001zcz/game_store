<?php

declare(strict_types=1);

namespace GameStore\Tests\Unit;

use GameStore\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
	/** @return iterable<string, array{int|float|string, int}> */
	public static function validAmounts(): iterable
	{
		yield 'integer' => [500, 50_000];
		yield 'decimal string' => ['500.25', 50_025];
		yield 'one decimal' => ['0.5', 50];
		yield 'float' => [12.34, 1_234];
		yield 'zero' => [0, 0];
	}

	#[DataProvider('validAmounts')]
	public function testConvertsToMinorUnits(int|float|string $amount, int $expected): void
	{
		self::assertSame($expected, Money::toMinor($amount));
	}

	public function testRejectsMoreThanTwoDecimals(): void
	{
		$this->expectException(InvalidArgumentException::class);
		Money::toMinor('10.001');
	}

	public function testDoesNotSilentlyRoundJsonFloat(): void
	{
		$this->expectException(InvalidArgumentException::class);
		Money::toMinor(10.001);
	}

	public function testFormatsMinorUnitsWithoutFloatMath(): void
	{
		self::assertSame('1290.00', Money::fromMinor(129_000));
		self::assertSame('0.05', Money::fromMinor(5));
	}
}
