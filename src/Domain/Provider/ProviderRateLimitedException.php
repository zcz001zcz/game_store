<?php

declare(strict_types=1);

namespace GameStore\Domain\Provider;

final class ProviderRateLimitedException extends RetryableDeliveryException
{
    public function __construct(public readonly int $retryAfterMs)
    {
        parent::__construct('Provider rate limit reached');
    }
}
