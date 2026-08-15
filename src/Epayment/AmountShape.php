<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * The `{currency, value}` wire shape Vipps uses for money, value in minor
 * units. One class owns both directions so request payloads and response
 * mapping cannot drift apart. Reading is deliberately forgiving: an absent
 * or malformed field maps to null rather than throwing, because aggregate
 * amounts legitimately come and go with payment state.
 *
 * @internal
 */
final readonly class AmountShape
{
    /**
     * @return array{currency: string, value: int}
     */
    public static function toArray(Amount $amount): array
    {
        return ['currency' => $amount->currency, 'value' => $amount->minorUnits];
    }

    public static function fromField(mixed $value): ?Amount
    {
        if (! is_array($value)) {
            return null;
        }

        $currency = $value['currency'] ?? null;
        $minorUnits = $value['value'] ?? null;

        if (! is_string($currency) || ! is_int($minorUnits)) {
            return null;
        }

        // Amount enforces non-negative + ISO 4217. A response violating that
        // is a Vipps contract break, surfaced as an absent amount instead of
        // an exception that would wrongly blame the integrator's config.
        try {
            return Amount::fromMinor($minorUnits, $currency);
        } catch (VippsConfigException) {
            return null;
        }
    }
}
