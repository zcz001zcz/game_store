<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

final class ConflictException extends HttpException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct(409, $message, 'conflict', $details);
    }
}
