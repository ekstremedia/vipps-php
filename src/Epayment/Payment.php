<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

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
     *
     * @throws VippsMalformedResponseException when `state` is missing or unknown
     */
    public static function fromArray(array $data): self
    {
        $reference = $data['reference'] ?? null;

        // tryFrom, not from: a state this SDK does not know (Vipps extends
        // enums without notice) must surface as the SDK's own
        // contract-violation exception — catchable at a `VippsException`
        // boundary — not as a native ValueError escaping the documented
        // throw surface.
        $state = $data['state'] ?? null;
        if (! is_string($state) || $state === '') {
            throw VippsMalformedResponseException::missingField('epayment payment', 'state');
        }

        $paymentState = PaymentState::tryFrom($state)
            ?? throw VippsMalformedResponseException::unexpectedValue('epayment payment', 'state', $state);

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
            state: $paymentState,
            authorizedAmount: AmountShape::fromField($aggregate['authorizedAmount'] ?? null),
            capturedAmount: AmountShape::fromField($aggregate['capturedAmount'] ?? null),
            refundedAmount: AmountShape::fromField($aggregate['refundedAmount'] ?? null),
            cancelledAmount: AmountShape::fromField($aggregate['cancelledAmount'] ?? null),
            paymentMethod: $paymentMethod !== null ? PaymentMethod::fromArray($paymentMethod) : null,
            profile: $profile !== null ? PaymentProfile::fromArray($profile) : null,
        );
    }
}
