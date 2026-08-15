<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * One agreement as Vipps reports it. Mapping is tolerant on purpose — Vipps
 * adds response fields without notice, so unknown keys are ignored and
 * missing optionals map to null. The status is the exception: an absent or
 * unrecognisable status on a payment mandate must fail loudly rather than
 * be guessed at — as VippsMalformedResponseException, so it still lands in
 * a `catch (VippsException)` boundary instead of escaping as a ValueError.
 */
final readonly class Agreement
{
    public function __construct(
        public string $id,
        public AgreementStatus $status,
        public Pricing $pricing,
        public Interval $interval,
        public string $productName,
        public ?string $productDescription = null,
        public ?string $externalId = null,
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $stop = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = ResponseField::stringOrNull($data, 'status')
            ?? throw VippsMalformedResponseException::missingField('recurring agreement', 'status');

        return new self(
            id: ResponseField::stringOrNull($data, 'id') ?? '',
            status: AgreementStatus::tryFrom($status)
                ?? throw VippsMalformedResponseException::unexpectedValue('recurring agreement', 'status', $status),
            pricing: Pricing::fromArray(ResponseField::arrayAt($data, 'pricing')),
            interval: Interval::fromArray(ResponseField::arrayAt($data, 'interval')),
            productName: ResponseField::stringOrNull($data, 'productName') ?? '',
            productDescription: ResponseField::stringOrNull($data, 'productDescription'),
            externalId: ResponseField::stringOrNull($data, 'externalId'),
            start: ResponseField::dateOrNull($data, 'start'),
            stop: ResponseField::dateOrNull($data, 'stop'),
        );
    }
}
