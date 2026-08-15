<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The real clock. Everything time-sensitive in the SDK (token freshness,
 * webhook timestamp skew) takes a ClockInterface so tests can freeze time
 * instead of sleeping.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
