<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * What POST /agreements answers. Persist agreementId (next to the idempotency
 * key you already stored) BEFORE sending the user to vippsConfirmationUrl —
 * once they leave your site, that id is the only handle for finding out
 * whether they ever approved. chargeId is only set when the NewAgreement
 * carried an initialCharge, and this response is the only convenient place
 * to learn it — persist it too if you need to track that first charge.
 *
 * Both required fields fail loudly when absent: an empty agreementId would
 * be persisted as the permanent handle for nothing, and an empty
 * vippsConfirmationUrl would redirect the user to the merchant's own origin.
 */
final readonly class CreatedAgreement
{
    public function __construct(
        public string $agreementId,
        public string $vippsConfirmationUrl,
        public ?string $chargeId = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ResponseField::stringOrNull($data, 'agreementId')
                ?? throw VippsMalformedResponseException::missingField('recurring agreement creation', 'agreementId'),
            ResponseField::stringOrNull($data, 'vippsConfirmationUrl')
                ?? throw VippsMalformedResponseException::missingField('recurring agreement creation', 'vippsConfirmationUrl'),
            ResponseField::stringOrNull($data, 'chargeId'),
        );
    }
}
