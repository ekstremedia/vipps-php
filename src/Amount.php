<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * A monetary amount in minor units (øre/cents), the only representation the
 * Vipps APIs accept. Constructed from integers exclusively — floats are
 * refused by design, because 0.1 + 0.2 style drift in a payment amount is a
 * bug you find in a settlement report weeks later.
 */
final readonly class Amount
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits < 0) {
            throw new VippsConfigException('Amount cannot be negative.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VippsConfigException("Currency must be a three-letter ISO 4217 code, got \"{$currency}\".");
        }
    }

    public static function fromMinor(int $minorUnits, string $currency = 'NOK'): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Whole main units plus optional minor remainder: fromMajor(49) is
     * 49.00 NOK, fromMajor(49, 50) is 49.50 NOK.
     */
    public static function fromMajor(int $major, int $minorRemainder = 0, string $currency = 'NOK'): self
    {
        if ($minorRemainder < 0 || $minorRemainder > 99) {
            throw new VippsConfigException('Minor remainder must be between 0 and 99.');
        }

        return new self($major * 100 + $minorRemainder, $currency);
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }
}
