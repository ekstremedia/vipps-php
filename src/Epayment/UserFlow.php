<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

/**
 * How the paying customer reaches the Vipps app. WEB_REDIRECT (the default)
 * bounces through the hosted landing page and works everywhere;
 * NATIVE_REDIRECT is the app-to-app handover for merchants with their own
 * mobile app; PUSH_MESSAGE skips the browser entirely and pings the
 * customer's phone (requires their phone number on the payment); QR renders
 * a code for a bystander's phone to scan.
 */
enum UserFlow: string
{
    case WebRedirect = 'WEB_REDIRECT';
    // The spec value is NATIVE_REDIRECT — a bare 'NATIVE' (shipped in 0.1.0)
    // is rejected by the API with a validation error, so app-to-app payment
    // creation never worked until this rename.
    case NativeRedirect = 'NATIVE_REDIRECT';
    case PushMessage = 'PUSH_MESSAGE';
    case Qr = 'QR';
}
