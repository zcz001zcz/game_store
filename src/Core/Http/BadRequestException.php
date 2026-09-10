<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

final class BadRequestException extends HttpException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct(400, $message, 'bad_request', $details);
    }
}
