<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

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
     *
     * @throws VippsMalformedResponseException when `reference` is missing or empty
     */
    public static function fromArray(array $data): self
    {
        // The reference is the payment's identity in every later call (get,
        // capture, cancel, refund). Mapping its absence to '' would strand
        // the merchant with a payment they can never address — better to
        // fail loudly on the contract violation than quietly on the next call.
        $reference = $data['reference'] ?? null;
        if (! is_string($reference) || $reference === '') {
            throw VippsMalformedResponseException::missingField('epayment created payment', 'reference');
        }

        $redirectUrl = $data['redirectUrl'] ?? null;

        return new self(
            reference: $reference,
            redirectUrl: is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
        );
    }
}
