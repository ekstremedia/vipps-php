<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Amount;

/**
 * An optional charge taken the moment the user approves the agreement — for
 * a first period that starts immediately instead of on the first due date.
 * The payload carries no currency field: an initial charge is always in the
 * agreement's pricing currency, so the Amount's currency is intentionally
 * not sent.
 */
final readonly class InitialCharge
{
    public function __construct(
        public Amount $amount,
        public ChargeTransactionType $transactionType,
        public string $description,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->minorUnits,
            'transactionType' => $this->transactionType->value,
            'description' => $this->description,
        ];
    }
}
