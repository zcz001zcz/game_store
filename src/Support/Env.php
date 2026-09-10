<?php

declare(strict_types=1);

namespace GameStore\Support;

use RuntimeException;

final class Env
{
    public static function string(string $key, ?string $default = null): string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Environment variable %s is required', $key));
        }

        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException(sprintf('Environment variable %s must be an integer', $key));
        }

        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new RuntimeException(sprintf('Environment variable %s must be boolean-like', $key));
        }

        return $parsed;
    }
}
