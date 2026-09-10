<?php

declare(strict_types=1);

namespace GameStore\Domain\Provider;

final readonly class ProviderAuditResult
{
    private function __construct(
        public string $outcome,
        public ?string $code = null,
        public ?string $orderId = null,
        public ?string $requestedSku = null,
        public ?string $actualSku = null,
        public ?string $reason = null,
    ) {
    }

    public static function found(string $code, string $orderId, string $requestedSku, string $actualSku): self
    {
        return new self('found', $code, $orderId, $requestedSku, $actualSku);
    }

    public static function notFound(): self
    {
        return new self('not_found', reason: 'not_found');
    }

    public static function uncertain(string $reason): self
    {
        return new self('uncertain', reason: $reason);
    }

    public function isFound(): bool
    {
        return $this->outcome === 'found';
    }
}
