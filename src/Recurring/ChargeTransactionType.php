<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * How a charge takes the money. DIRECT_CAPTURE collects in one step on the
 * due date — right when delivery is continuous (subscriptions, memberships).
 * RESERVE_CAPTURE only reserves; the merchant must capture explicitly once it
 * has delivered, because the Vipps terms forbid capturing before delivery.
 */
enum ChargeTransactionType: string
{
    case DirectCapture = 'DIRECT_CAPTURE';
    case ReserveCapture = 'RESERVE_CAPTURE';
}
