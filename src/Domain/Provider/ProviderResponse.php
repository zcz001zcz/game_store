<?php

declare(strict_types=1);

namespace GameStore\Domain\Provider;

final readonly class ProviderResponse
{
    private function __construct(
        public string $outcome,
        public ?string $code = null,
        public ?string $reason = null,
    ) {
    }

    public static function success(string $code, ?string $reason = null): self
    {
        return new self('success', $code, $reason);
    }

    public static function outOfStock(): self
    {
        return new self('out_of_stock', reason: 'out_of_stock');
    }

    public static function unavailable(string $reason = 'unavailable'): self
    {
        return new self('unavailable', reason: $reason);
    }

    public static function uncertain(string $reason): self
    {
        return new self('uncertain', reason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === 'success';
    }

    public function isUncertain(): bool
    {
        return $this->outcome === 'uncertain';
    }
}
