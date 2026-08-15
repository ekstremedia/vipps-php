<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * How the customer paid (or will pay). type stays a plain string — WALLET
 * is the ordinary Vipps flow, CARD exists today, and Vipps adds methods
 * without notice, so an enum here would turn a new method into a crash.
 * cardBin appears only for card payments where the merchant may see it.
 */
final readonly class PaymentMethod
{
    public function __construct(
        public string $type,
        public ?string $cardBin = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $cardBin = $data['cardBin'] ?? null;

        return new self(
            type: is_string($type) ? $type : '',
            cardBin: is_string($cardBin) && $cardBin !== '' ? $cardBin : null,
        );
    }
}
