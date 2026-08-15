<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * The customer's OIDC subject on a payment. Vipps only fills sub in when
 * the payment requested a profile scope AND the customer consented, so a
 * null sub is the normal case, not an error.
 */
final readonly class PaymentProfile
{
    public function __construct(
        public ?string $sub = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sub = $data['sub'] ?? null;

        return new self(sub: is_string($sub) && $sub !== '' ? $sub : null);
    }
}
