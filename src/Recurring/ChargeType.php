<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * RECURRING is the cadence-following charge and what Vipps assumes when the
 * field is omitted. UNSCHEDULED marks a charge outside the agreed interval —
 * a top-up, a one-off fee — so it is presented honestly to the user instead
 * of masquerading as the regular bill.
 */
enum ChargeType: string
{
    case Recurring = 'RECURRING';
    case Unscheduled = 'UNSCHEDULED';
}
