<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * POST /epayment/v1/payments response: where to send the customer next.
 * redirectUrl is null for flows without a browser hop (PUSH_MESSAGE pings
 * the customer's phone instead), so callers branch on their chosen flow
 * rather than assuming a URL exists.
 */
final readonly class CreatedPayment
{
    public function __construct(
        public string $reference,
        public ?string $redirectUrl = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reference = $data['reference'] ?? null;
        $redirectUrl = $data['redirectUrl'] ?? null;

        return new self(
            reference: is_string($reference) ? $reference : '',
            redirectUrl: is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
        );
    }
}
