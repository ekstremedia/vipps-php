<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * One agreement as Vipps reports it. Mapping is tolerant on purpose — Vipps
 * adds response fields without notice, so unknown keys are ignored and
 * missing optionals map to null. The identity and mandate fields are the
 * exception: an absent id (the only handle for ever addressing the
 * agreement again), productName (what the user actually approved paying
 * for), status, or interval must fail loudly rather than be guessed at —
 * as VippsMalformedResponseException, so they still land in a
 * `catch (VippsException)` boundary instead of escaping as a ValueError.
 *
 * interval is null only for FLEXIBLE agreements: v3's flexible model has no
 * fixed cadence, so Vipps may omit the field there — everywhere else an
 * absent interval is a malformed response.
 */
final readonly class Agreement
{
    public function __construct(
        public string $id,
        public AgreementStatus $status,
        public Pricing $pricing,
        public ?Interval $interval,
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

        $pricing = Pricing::fromArray(ResponseField::arrayAt($data, 'pricing'));

        $intervalData = $data['interval'] ?? null;
        $interval = is_array($intervalData) ? Interval::fromArray($intervalData) : null;
        if ($interval === null && $pricing->type !== PricingType::Flexible) {
            throw VippsMalformedResponseException::missingField('recurring agreement', 'interval');
        }

        return new self(
            id: ResponseField::stringOrNull($data, 'id')
                ?? throw VippsMalformedResponseException::missingField('recurring agreement', 'id'),
            status: AgreementStatus::tryFrom($status)
                ?? throw VippsMalformedResponseException::unexpectedValue('recurring agreement', 'status', $status),
            pricing: $pricing,
            interval: $interval,
            productName: ResponseField::stringOrNull($data, 'productName')
                ?? throw VippsMalformedResponseException::missingField('recurring agreement', 'productName'),
            productDescription: ResponseField::stringOrNull($data, 'productDescription'),
            externalId: ResponseField::stringOrNull($data, 'externalId'),
            start: ResponseField::dateOrNull($data, 'start'),
            stop: ResponseField::dateOrNull($data, 'stop'),
        );
    }
}
