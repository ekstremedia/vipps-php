<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock the test drives by hand. Deliberately mutable (unlike everything in
 * src/) so a single instance can be shared with the object under test and
 * then advanced mid-test — sleeping in a test suite is never acceptable, and
 * freshness math is only trustworthy when the test controls both "now"s.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $time): self
    {
        return new self(new DateTimeImmutable($time));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("{$seconds} seconds");
    }
}
