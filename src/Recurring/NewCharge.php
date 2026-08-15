<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeInterface;
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * A charge to create on an agreement. `due` is a plain date — Vipps collects
 * some time during that day, not at a clock time — and must be at least one
 * day ahead when the charge is created. retryDays (0–14) is how many days
 * Vipps keeps retrying a failed collection after the due date before marking
 * the charge FAILED; 0 means one attempt only. No currency field: a charge
 * is always in the agreement's pricing currency.
 */
final readonly class NewCharge
{
    public string $due;

    public function __construct(
        public Amount $amount,
        public ChargeTransactionType $transactionType,
        public string $description,
        DateTimeInterface|string $due,
        public int $retryDays,
        public ?ChargeType $type = null,
        public ?string $externalId = null,
        public ?string $orderId = null,
    ) {
        if ($retryDays < 0 || $retryDays > 14) {
            throw new VippsConfigException('retryDays must be between 0 and 14.');
        }

        if ($due instanceof DateTimeInterface) {
            $this->due = $due->format('Y-m-d');
        } else {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1) {
                throw new VippsConfigException("due must be an ISO date (YYYY-MM-DD), got \"{$due}\".");
            }

            $this->due = $due;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'amount' => $this->amount->minorUnits,
            'transactionType' => $this->transactionType->value,
            'description' => $this->description,
            'due' => $this->due,
            'retryDays' => $this->retryDays,
        ];

        if ($this->type !== null) {
            $payload['type'] = $this->type->value;
        }
        if ($this->externalId !== null) {
            $payload['externalId'] = $this->externalId;
        }
        if ($this->orderId !== null) {
            $payload['orderId'] = $this->orderId;
        }

        return $payload;
    }
}
