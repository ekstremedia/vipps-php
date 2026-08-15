<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * One charge as Vipps reports it. Every amount shares the response's
 * currency field — a charge is always in its agreement's currency. Money
 * totals live only in the required `summary` object (see ChargeSummary);
 * failureReason/failureDescription are only set once a collection attempt
 * has failed. Same tolerance policy as Agreement: unknown keys are ignored
 * and missing optionals map to null, but the fields money decisions hang
 * on — status, amount, currency and summary — fail loudly with
 * VippsMalformedResponseException rather than being guessed at (a missing
 * amount read as 0 would let a caller capture or refund zero minor units).
 */
final readonly class Charge
{
    public function __construct(
        public string $id,
        public ChargeStatus $status,
        public Amount $amount,
        public ChargeSummary $summary,
        public string $description = '',
        public ?DateTimeImmutable $due = null,
        public ?ChargeTransactionType $transactionType = null,
        public ?int $retryDays = null,
        public ?string $failureReason = null,
        public ?string $failureDescription = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $currency = ResponseField::currency($data, 'recurring charge');
        $transactionType = ResponseField::stringOrNull($data, 'transactionType');

        $status = ResponseField::stringOrNull($data, 'status')
            ?? throw VippsMalformedResponseException::missingField('recurring charge', 'status');

        $summary = $data['summary'] ?? null;
        if (! is_array($summary)) {
            throw VippsMalformedResponseException::missingField('recurring charge', 'summary');
        }

        return new self(
            id: ResponseField::stringOrNull($data, 'id') ?? '',
            status: ChargeStatus::tryFrom($status)
                ?? throw VippsMalformedResponseException::unexpectedValue('recurring charge', 'status', $status),
            amount: Amount::fromMinor(
                ResponseField::intOrNull($data, 'amount')
                    ?? throw VippsMalformedResponseException::missingField('recurring charge', 'amount'),
                $currency,
            ),
            summary: ChargeSummary::fromArray($summary, $currency),
            description: ResponseField::stringOrNull($data, 'description') ?? '',
            due: ResponseField::dateOrNull($data, 'due'),
            transactionType: $transactionType !== null ? ChargeTransactionType::tryFrom($transactionType) : null,
            retryDays: ResponseField::intOrNull($data, 'retryDays'),
            failureReason: ResponseField::stringOrNull($data, 'failureReason'),
            failureDescription: ResponseField::stringOrNull($data, 'failureDescription'),
        );
    }
}
