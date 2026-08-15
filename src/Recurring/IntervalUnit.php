<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * The calendar unit an agreement's cadence is expressed in, combined with a
 * count in Interval: every 1 MONTH, every 2 WEEKs, and so on.
 */
enum IntervalUnit: string
{
    case Day = 'DAY';
    case Week = 'WEEK';
    case Month = 'MONTH';
    case Year = 'YEAR';
}
