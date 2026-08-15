<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * The billing cadence: every $count $unit. Only the lower bound is validated
 * here — Vipps enforces its own per-unit upper limits server-side, and
 * mirroring them locally would just drift out of date.
 */
final readonly class Interval
{
    public function __construct(
        public IntervalUnit $unit,
        public int $count,
    ) {
        if ($count < 1) {
            throw new VippsConfigException('Interval count must be at least 1.');
        }
    }

    public static function days(int $count): self
    {
        return new self(IntervalUnit::Day, $count);
    }

    public static function weeks(int $count): self
    {
        return new self(IntervalUnit::Week, $count);
    }

    public static function months(int $count): self
    {
        return new self(IntervalUnit::Month, $count);
    }

    public static function years(int $count): self
    {
        return new self(IntervalUnit::Year, $count);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $unit = $data['unit'] ?? null;
        $count = $data['count'] ?? null;

        return new self(
            is_string($unit) ? (IntervalUnit::tryFrom($unit) ?? IntervalUnit::Month) : IntervalUnit::Month,
            is_int($count) && $count >= 1 ? $count : 1,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'unit' => $this->unit->value,
            'count' => $this->count,
        ];
    }
}
