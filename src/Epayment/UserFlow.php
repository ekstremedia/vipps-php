<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * How the paying customer reaches the Vipps app. WEB_REDIRECT (the default)
 * bounces through the hosted landing page and works everywhere; NATIVE is
 * the app-to-app handover for merchants with their own mobile app;
 * PUSH_MESSAGE skips the browser entirely and pings the customer's phone
 * (requires their phone number on the payment); QR renders a code for a
 * bystander's phone to scan.
 */
enum UserFlow: string
{
    case WebRedirect = 'WEB_REDIRECT';
    case Native = 'NATIVE';
    case PushMessage = 'PUSH_MESSAGE';
    case Qr = 'QR';
}
