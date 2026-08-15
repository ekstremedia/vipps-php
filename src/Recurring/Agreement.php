<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;

/**
 * One agreement as Vipps reports it. Mapping is tolerant on purpose — Vipps
 * adds response fields without notice, so unknown keys are ignored and
 * missing optionals map to null. The status is the exception: an
 * unrecognisable status on a payment mandate should fail loudly rather than
 * be guessed at, so AgreementStatus::from() is used unshielded.
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
        return new self(
            id: ResponseField::stringOrNull($data, 'id') ?? '',
            status: AgreementStatus::from(ResponseField::stringOrNull($data, 'status') ?? ''),
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
