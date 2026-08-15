<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

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
     * Response mapping is strict where the constructor is lenient about
     * provenance: a cadence Vipps reports that we cannot represent must not
     * be silently relabelled "every month" — the merchant would schedule
     * charges on the wrong rhythm without any error to notice.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $unit = $data['unit'] ?? null;
        if (! is_string($unit) || $unit === '') {
            throw VippsMalformedResponseException::missingField('recurring agreement', 'interval.unit');
        }

        $count = $data['count'] ?? null;
        if (! is_int($count)) {
            throw VippsMalformedResponseException::missingField('recurring agreement', 'interval.count');
        }
        if ($count < 1) {
            throw VippsMalformedResponseException::unexpectedValue('recurring agreement', 'interval.count', (string) $count);
        }

        return new self(
            IntervalUnit::tryFrom($unit)
                ?? throw VippsMalformedResponseException::unexpectedValue('recurring agreement', 'interval.unit', $unit),
            $count,
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
