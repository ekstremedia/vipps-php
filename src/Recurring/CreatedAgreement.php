<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * What POST /agreements answers. Persist agreementId (next to the idempotency
 * key you already stored) BEFORE sending the user to vippsConfirmationUrl —
 * once they leave your site, that id is the only handle for finding out
 * whether they ever approved.
 */
final readonly class CreatedAgreement
{
    public function __construct(
        public string $agreementId,
        public string $vippsConfirmationUrl,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ResponseField::stringOrNull($data, 'agreementId') ?? '',
            ResponseField::stringOrNull($data, 'vippsConfirmationUrl') ?? '',
        );
    }
}
