<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
