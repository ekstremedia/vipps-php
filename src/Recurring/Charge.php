<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Nesthus\Vipps\Amount;

/**
 * One charge as Vipps reports it. Both amounts share the response's currency
 * field — a charge is always in its agreement's currency. amountRefunded is
 * null until a refund has happened; failureReason/failureCode are only set
 * once a collection attempt has failed. Same tolerance policy as Agreement:
 * unknown keys ignored, missing optionals null, but an unknown status fails
 * loudly via ChargeStatus::from().
 */
final readonly class Charge
{
    public function __construct(
        public string $id,
        public ChargeStatus $status,
        public Amount $amount,
        public ?Amount $amountRefunded = null,
        public string $description = '',
        public ?DateTimeImmutable $due = null,
        public ?ChargeTransactionType $transactionType = null,
        public ?int $retryDays = null,
        public ?string $failureReason = null,
        public ?string $failureCode = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $currency = ResponseField::currency($data);
        $amountRefunded = ResponseField::intOrNull($data, 'amountRefunded');
        $transactionType = ResponseField::stringOrNull($data, 'transactionType');

        return new self(
            id: ResponseField::stringOrNull($data, 'id') ?? '',
            status: ChargeStatus::from(ResponseField::stringOrNull($data, 'status') ?? ''),
            amount: Amount::fromMinor(ResponseField::intOrNull($data, 'amount') ?? 0, $currency),
            amountRefunded: $amountRefunded !== null ? Amount::fromMinor($amountRefunded, $currency) : null,
            description: ResponseField::stringOrNull($data, 'description') ?? '',
            due: ResponseField::dateOrNull($data, 'due'),
            transactionType: $transactionType !== null ? ChargeTransactionType::tryFrom($transactionType) : null,
            retryDays: ResponseField::intOrNull($data, 'retryDays'),
            failureReason: ResponseField::stringOrNull($data, 'failureReason'),
            failureCode: ResponseField::stringOrNull($data, 'failureCode'),
        );
    }
}
