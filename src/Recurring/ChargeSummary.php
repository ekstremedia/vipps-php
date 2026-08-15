<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * The charge's money totals as v3 reports them — the response's required
 * `summary` object. This is the ONLY place captured/refunded/cancelled
 * totals exist in v3 (there is no top-level amountRefunded field), and
 * guards like "has this charge already been refunded?" read them here.
 * Because those guards make money decisions, a summary with holes is
 * refused loudly instead of padded with zeros: a missing `refunded` read
 * as 0 would wave a second refund straight through.
 */
final readonly class ChargeSummary
{
    public function __construct(
        public Amount $captured,
        public Amount $refunded,
        public Amount $cancelled,
    ) {}

    /**
     * @param array<mixed> $data the decoded `summary` object; the charge's currency applies to all totals
     */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            captured: self::total($data, 'captured', $currency),
            refunded: self::total($data, 'refunded', $currency),
            cancelled: self::total($data, 'cancelled', $currency),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function total(array $data, string $field, string $currency): Amount
    {
        $minorUnits = ResponseField::intOrNull($data, $field)
            ?? throw VippsMalformedResponseException::missingField('recurring charge', "summary.{$field}");

        return Amount::fromMinor($minorUnits, $currency);
    }
}
