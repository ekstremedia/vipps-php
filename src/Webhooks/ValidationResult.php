<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

/**
 * Outcome of a webhook signature check. $reason is a stable snake_case slug
 * meant for the merchant's log: it NEVER contains the secret, the received
 * signature or the computed one, so logging it verbatim cannot leak signing
 * material. Branch on the slug, not on prose — the slugs are the contract.
 */
final readonly class ValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $reason,
    ) {}

    public static function valid(): self
    {
        return new self(true, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
