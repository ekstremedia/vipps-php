<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * Charge lifecycle. The normal flow is PENDING → DUE (the user has been
 * notified) → PROCESSING on the due date, then CHARGED for DIRECT_CAPTURE or
 * RESERVED for RESERVE_CAPTURE, where the money is only held until the
 * merchant captures. FAILED is terminal — Vipps only sets it after the
 * charge's retryDays have been exhausted, so don't re-create a failed charge
 * while it is still PROCESSING.
 */
enum ChargeStatus: string
{
    case Pending = 'PENDING';
    case Due = 'DUE';
    case Processing = 'PROCESSING';
    case Charged = 'CHARGED';
    case Reserved = 'RESERVED';
    case PartiallyCaptured = 'PARTIALLY_CAPTURED';
    case Failed = 'FAILED';
    case Refunded = 'REFUNDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Cancelled = 'CANCELLED';
}
