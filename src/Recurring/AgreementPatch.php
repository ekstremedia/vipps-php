<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * A partial update to a live agreement — only non-null fields are sent, so
 * everything else stays as it is. `price` updates pricing.amount on a LEGACY
 * agreement, `suggestedMaxAmount` the ceiling on a VARIABLE one; the currency
 * can never change, which is why neither emits one. Vipps itself notifies the
 * user of a price increase. Status changes are deliberately NOT here — the
 * only legal one is STOPPED, and that is RecurringApi::stopAgreement().
 */
final readonly class AgreementPatch
{
    public function __construct(
        public ?string $productName = null,
        public ?string $productDescription = null,
        public ?Amount $price = null,
        public ?Amount $suggestedMaxAmount = null,
        public ?string $externalId = null,
    ) {
        if ($productName === null && $productDescription === null && $price === null
            && $suggestedMaxAmount === null && $externalId === null) {
            throw new VippsConfigException('AgreementPatch is empty — set at least one field to change.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [];

        if ($this->productName !== null) {
            $payload['productName'] = $this->productName;
        }
        if ($this->productDescription !== null) {
            $payload['productDescription'] = $this->productDescription;
        }

        $pricing = [];
        if ($this->price !== null) {
            $pricing['amount'] = $this->price->minorUnits;
        }
        if ($this->suggestedMaxAmount !== null) {
            $pricing['suggestedMaxAmount'] = $this->suggestedMaxAmount->minorUnits;
        }
        if ($pricing !== []) {
            $payload['pricing'] = $pricing;
        }

        if ($this->externalId !== null) {
            $payload['externalId'] = $this->externalId;
        }

        return $payload;
    }
}
