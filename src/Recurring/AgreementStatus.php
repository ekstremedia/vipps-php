<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * Agreement lifecycle. Two easy misreadings: EXPIRED does not mean "ran its
 * course" — it means the agreement was never accepted, because the user
 * rejected it or the 10-minute approval window on the confirmation page
 * lapsed. And STOPPED is final: a stopped agreement can never be reactivated,
 * the user has to approve a brand-new one.
 */
enum AgreementStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Stopped = 'STOPPED';
    case Expired = 'EXPIRED';
}
