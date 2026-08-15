<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;

/**
 * GET /epayment/v1/payments/{reference} — a snapshot of one payment. Money
 * movement lives in the four aggregate amounts, not the state: a fully
 * captured, even fully refunded payment still reports AUTHORIZED, so "how
 * much can I still capture/refund" is always aggregate arithmetic.
 * Aggregates Vipps has not touched yet may be absent, hence nullable.
 */
final readonly class Payment
{
    public function __construct(
        public string $reference,
        public PaymentState $state,
        public ?Amount $authorizedAmount = null,
        public ?Amount $capturedAmount = null,
        public ?Amount $refundedAmount = null,
        public ?Amount $cancelledAmount = null,
        public ?PaymentMethod $paymentMethod = null,
        public ?PaymentProfile $profile = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reference = $data['reference'] ?? null;
        $state = $data['state'] ?? null;

        $aggregate = $data['aggregate'] ?? null;
        if (! is_array($aggregate)) {
            $aggregate = [];
        }

        /** @var array<string, mixed>|null $paymentMethod */
        $paymentMethod = is_array($data['paymentMethod'] ?? null) ? $data['paymentMethod'] : null;

        /** @var array<string, mixed>|null $profile */
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : null;

        return new self(
            reference: is_string($reference) ? $reference : '',
            state: PaymentState::from(is_string($state) ? $state : ''),
            authorizedAmount: AmountShape::fromField($aggregate['authorizedAmount'] ?? null),
            capturedAmount: AmountShape::fromField($aggregate['capturedAmount'] ?? null),
            refundedAmount: AmountShape::fromField($aggregate['refundedAmount'] ?? null),
            cancelledAmount: AmountShape::fromField($aggregate['cancelledAmount'] ?? null),
            paymentMethod: $paymentMethod !== null ? PaymentMethod::fromArray($paymentMethod) : null,
            profile: $profile !== null ? PaymentProfile::fromArray($profile) : null,
        );
    }
}
