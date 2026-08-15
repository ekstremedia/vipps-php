<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * Where a payment sits in the reserve-then-capture lifecycle. AUTHORIZED is
 * the only state in which money is held. There is deliberately no CAPTURED
 * or REFUNDED state — a fully captured, even fully refunded payment still
 * reports AUTHORIZED, and money movement is read from the aggregate amounts
 * and the event log instead. ABORTED is the customer saying no, EXPIRED is
 * the customer never answering, TERMINATED is the merchant voiding the hold.
 */
enum PaymentState: string
{
    case Created = 'CREATED';
    case Aborted = 'ABORTED';
    case Expired = 'EXPIRED';
    case Authorized = 'AUTHORIZED';
    case Terminated = 'TERMINATED';
}
