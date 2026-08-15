<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * An agreement's pricing model. Constructed through legacy()/variable()/
 * flexible() on the request side — the private constructor is what
 * guarantees a LEGACY pricing always carries its amount, a VARIABLE one its
 * ceiling, and a FLEXIBLE one neither, so toPayload() can never emit a
 * half-built model. maxAmount is response-only: the ceiling the customer
 * actually approved (they may adjust the merchant's suggestedMaxAmount), so
 * it is never sent back out.
 */
final readonly class Pricing
{
    private function __construct(
        public PricingType $type,
        public string $currency,
        public ?Amount $amount = null,
        public ?Amount $suggestedMaxAmount = null,
        public ?Amount $maxAmount = null,
    ) {}

    /**
     * A fixed price per charge, shown to the user at approval.
     */
    public static function legacy(Amount $amount): self
    {
        return new self(PricingType::Legacy, $amount->currency, amount: $amount);
    }

    /**
     * A user-approved ceiling instead of a fixed price — each charge then
     * carries its own amount up to this.
     */
    public static function variable(Amount $suggestedMaxAmount): self
    {
        return new self(PricingType::Variable, $suggestedMaxAmount->currency, suggestedMaxAmount: $suggestedMaxAmount);
    }

    /**
     * No amount, no ceiling — deliberately. A FLEXIBLE agreement's request
     * payload is `{type, currency}` and nothing else (per the v3 spec): the
     * user approves no price up front, so every charge under it carries its
     * own amount. Takes a bare currency string because there is no Amount to
     * borrow one from; validated with Amount's own ISO 4217 rule.
     */
    public static function flexible(string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VippsConfigException("Currency must be a three-letter ISO 4217 code, got \"{$currency}\".");
        }

        return new self(PricingType::Flexible, $currency);
    }

    /**
     * A missing type still reads as LEGACY (the documented default), but an
     * unknown one is refused rather than relabelled: reporting a FLEXIBLE or
     * future agreement as LEGACY tells the merchant the user approved a
     * fixed price they never saw.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = ResponseField::stringOrNull($data, 'type');
        $currency = ResponseField::currency($data, 'recurring agreement', 'pricing.currency');
        $amount = ResponseField::intOrNull($data, 'amount');
        $suggestedMaxAmount = ResponseField::intOrNull($data, 'suggestedMaxAmount');
        $maxAmount = ResponseField::intOrNull($data, 'maxAmount');

        return new self(
            type: $type !== null
                ? (PricingType::tryFrom($type)
                    ?? throw VippsMalformedResponseException::unexpectedValue('recurring agreement', 'pricing.type', $type))
                : PricingType::Legacy,
            currency: $currency,
            amount: $amount !== null ? Amount::fromMinor($amount, $currency) : null,
            suggestedMaxAmount: $suggestedMaxAmount !== null ? Amount::fromMinor($suggestedMaxAmount, $currency) : null,
            maxAmount: $maxAmount !== null ? Amount::fromMinor($maxAmount, $currency) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'type' => $this->type->value,
            'currency' => $this->currency,
        ];

        if ($this->amount !== null) {
            $payload['amount'] = $this->amount->minorUnits;
        }
        if ($this->suggestedMaxAmount !== null) {
            $payload['suggestedMaxAmount'] = $this->suggestedMaxAmount->minorUnits;
        }

        return $payload;
    }
}
