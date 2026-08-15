# nesthus/vipps-php

Unofficial framework-agnostic PHP SDK for Vipps MobilePay: ePayment, Recurring, Login (OIDC) and Webhooks.

[![CI](https://github.com/ekstremedia/vipps-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ekstremedia/vipps-php/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/nesthus/vipps-php)](https://packagist.org/packages/nesthus/vipps-php)
[![Downloads](https://img.shields.io/packagist/dt/nesthus/vipps-php)](https://packagist.org/packages/nesthus/vipps-php)
[![License](https://img.shields.io/packagist/l/nesthus/vipps-php)](LICENSE)

> [!IMPORTANT]
> **This is an unofficial SDK.** It is not affiliated with, endorsed or
> supported by Vipps MobilePay AS. *Vipps* and *MobilePay* are trademarks of
> Vipps MobilePay AS. When presenting the payment option to your users, follow
> the official brand guidelines: <https://brand.vippsmobilepay.com/>.

## Why this SDK

- **PSR-18 / PSR-17** — bring your own HTTP client; the SDK never picks one,
  so *your* timeout and proxy policy applies.
- **PHP 8.3+**, `final readonly` DTOs, native enums, `declare(strict_types=1)`
  throughout.
- **No serializer, no annotations, no framework** — the only runtime
  dependencies are PSR interfaces.
- **No floats for money.** Amounts are an `Amount` value object over integer
  minor units (øre/cents); float constructors don't exist.
- **Caller-owned idempotency keys.** Every mutating call *requires* one from
  you, because a key the SDK generated per call protects nothing — you must
  persist it before the request so you can replay with the same key.
- **Tested webhook signature validation** — HMAC verification with recomputed
  content hashes, constant-time comparison, replay-window enforcement, and
  leak-free failure reasons.

[zaporylie/vipps](https://github.com/zaporylie/vipps) is the long-standing
community alternative and may fit you better; this library exists as a
smaller, dependency-light take on the current API generation (ePayment v1,
Recurring v3, Webhooks).

## Install

```bash
composer require nesthus/vipps-php
```

The SDK depends only on PSR interfaces, so you also need a PSR-18 client and
PSR-17 factories. Guzzle provides all of them:

```bash
composer require guzzlehttp/guzzle
```

> [!WARNING]
> **Configure timeouts, or a hung call will wedge your worker.** Most PSR-18
> clients — Guzzle included — wait **forever** by default, and a payment SDK
> without deadlines turns one slow upstream call into a stuck process.
> Construct your client with explicit limits:
>
> ```php
> $client = new \GuzzleHttp\Client([
>     'timeout' => 15,          // whole request, seconds
>     'connect_timeout' => 5,   // TCP/TLS handshake, seconds
> ]);
> ```

## Setup

All four credential values come from the merchant portal's developer section,
per sales unit and per environment — test keys only work against the test
host, which is why the environment lives in the config next to the keys.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Environment;
use Nesthus\Vipps\SystemInfo;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

$config = new VippsConfig(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    subscriptionKey: 'your-subscription-key',      // Ocp-Apim-Subscription-Key
    merchantSerialNumber: '123456',
    environment: Environment::Test,                // Environment::Production when live
    system: new SystemInfo('acme-webshop', '2.4.1'), // your system's name + version, sent as Vipps-System-* headers
);

$httpFactory = new HttpFactory();                  // PSR-17 request + stream factory

$vipps = new Vipps(
    $config,
    new Client(['timeout' => 15, 'connect_timeout' => 5]),
    $httpFactory,
    $httpFactory,
);
```

Construction is free — everything inside is lazy. Access tokens are fetched
and cached automatically on the first authenticated call (see
[Token caching](#token-caching)).

## Recurring quick start

Two rules every integrator trips on: **the redirect back to your site proves
nothing about approval**, and **Vipps never bills anyone by itself** — your
scheduler creates every charge.

```php
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Recurring\AgreementStatus;
use Nesthus\Vipps\Recurring\ChargeTransactionType;
use Nesthus\Vipps\Recurring\Interval;
use Nesthus\Vipps\Recurring\NewAgreement;
use Nesthus\Vipps\Recurring\NewCharge;
use Nesthus\Vipps\Recurring\Pricing;

// 1. Create the agreement. Mint the idempotency key yourself and persist it
//    BEFORE the request — a key you cannot replay protects nothing.
$created = $vipps->recurring()->createAgreement(new NewAgreement(
    pricing: Pricing::legacy(Amount::fromMajor(49)),          // 49.00 NOK per charge
    interval: Interval::months(1),
    productName: 'Premium',
    merchantRedirectUrl: 'https://shop.example/vipps/return', // where the user lands afterwards
    merchantAgreementUrl: 'https://shop.example/account/subscription', // where they can manage/cancel (required by the Vipps terms)
), $idempotencyKey);

// 2. Persist $created->agreementId next to the key, THEN send the user off:
header('Location: ' . $created->vippsConfirmationUrl);

// 3. Never trust the redirect back as approval — users approve and never
//    return, or return without approving. Poll until the status leaves
//    PENDING (they have 10 minutes), or subscribe to the agreement webhooks.
$agreement = $vipps->recurring()->getAgreement($created->agreementId);

if ($agreement->status === AgreementStatus::Active) {
    // 4. An ACTIVE agreement moves no money. Your scheduled job creates every
    //    charge, at least 1 day before its due date. A missing scheduler looks
    //    exactly like "Vipps stopped charging our customers".
    $chargeId = $vipps->recurring()->createCharge($agreement->id, new NewCharge(
        amount: Amount::fromMajor(49),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'Premium — September',
        due: new DateTimeImmutable('+30 days'),   // a plain date; Vipps collects some time that day
        retryDays: 5,                             // days Vipps retries a failed collection (0–14)
    ), $chargeIdempotencyKey);
}
```

Also available: `listAgreements()`, `updateAgreement()` (price/text changes),
`stopAgreement()` (final — a stopped agreement can never be reactivated),
`listCharges()`, `getCharge()`, `getChargeById()` (higher rate limits; prefer
it in webhook handlers), `cancelCharge()`, `captureCharge()` (for
`RESERVE_CAPTURE` charges) and `refundCharge()`.

## ePayment quick start

One-off payments are reserve-then-capture: `AUTHORIZED` only **holds** the
money; `capture()` moves it, in full or in parts.

```php
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Epayment\CreatePayment;
use Nesthus\Vipps\Epayment\PaymentState;

$created = $vipps->epayment()->createPayment(new CreatePayment(
    amount: Amount::fromMajor(249, 50),           // 249.50 NOK
    reference: 'order-2026-000123',               // your permanent id: 8–64 chars of [a-zA-Z0-9-]
    returnUrl: 'https://shop.example/checkout/return',
), $idempotencyKey);

header('Location: ' . $created->redirectUrl);     // null for flows without a browser hop (e.g. PUSH_MESSAGE)

// Back on returnUrl — same rule as Recurring, the redirect proves nothing:
$payment = $vipps->epayment()->getPayment('order-2026-000123');

if ($payment->state === PaymentState::Authorized) {
    // Capture when you deliver — in full or in parts (ship half, capture half).
    // An authorization nobody captures expires on its own; cancel() releases it early.
    $vipps->epayment()->capture('order-2026-000123', Amount::fromMajor(249, 50), $captureKey);
}

// Money already captured goes back with refund():
$vipps->epayment()->refund('order-2026-000123', Amount::fromMajor(50), $refundKey);
```

The `reference` is your idempotent identity for the whole payment, distinct
from the per-request idempotency key: creating a second payment with a used
reference is answered with a 409, and that failure is the point — never
generate a fresh reference to "get past" it. Note that a fully captured, even
fully refunded payment still reports `AUTHORIZED` — money movement is read
from `Payment`'s aggregate amounts and `getEvents()`, not the state.

## Login quick start

A standard OIDC authorization-code flow, discovered at runtime from the
well-known document.

```php
use Nesthus\Vipps\Login\AuthorizationRequest;

// 1. Generate state (the CSRF binding) and a PKCE verifier, and store BOTH in
//    the session — they must survive until the redirect returns.
$state = bin2hex(random_bytes(16));
$codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

$url = $vipps->login()->buildAuthorizationUrl(new AuthorizationRequest(
    redirectUri: 'https://shop.example/auth/vipps/callback', // must exactly match one registered in the portal
    state: $state,
    codeVerifier: $codeVerifier,                             // SDK derives the S256 challenge; verifier never leaves you
));

// 2. On the callback: REFUSE unless the returned `state` matches the session's
//    (the SDK cannot do this — it has no session), then exchange the code with
//    the byte-identical redirectUri and the SAME verifier.
$tokens = $vipps->login()->exchangeCode(
    $_GET['code'],
    'https://shop.example/auth/vipps/callback',
    $codeVerifier,
);

$claims = $tokens->idTokenClaims();                    // sub, and whatever the granted scopes surface
$profile = $vipps->login()->userinfo($tokens->accessToken); // authorized by the USER's token, not the merchant's
```

> [!CAUTION]
> `idTokenClaims()` decodes the id token **without verifying the JWT
> signature**. That is safe for exactly one reason: this token arrived over
> TLS directly from Vipps' token endpoint in a confidential-client code
> exchange, so its origin is already authenticated by the channel. An id token
> received from anywhere else — a browser redirect, a mobile app, another
> service — **must not** be trusted this way; it could be forged freely. Full
> JWKS signature verification is a deliberate non-goal of v0.1; if you need to
> accept tokens from untrusted channels, bring a real JWT library.

## Webhooks

Register a callback URL per sales unit. Vipps caps how many hooks a sales unit
may hold, so list-and-reuse (`all()`) rather than re-registering on every
deploy.

```php
$hook = $vipps->webhooks()->register(
    'https://shop.example/hooks/vipps',
    ['epayments.payment.authorized.v1', 'epayments.payment.captured.v1'],
    $idempotencyKey,
);

// ⚠️ $hook->secret is shown EXACTLY ONCE — Vipps never re-reveals it, and
// all() returns id/url/events only. Persist it (encrypted, next to $hook->id)
// before doing anything else. If storage fails, delete() and register() again.
```

Verify every inbound delivery before trusting a byte of it:

```php
use Nesthus\Vipps\Webhooks\SignatureValidator;
use Nesthus\Vipps\Webhooks\WebhookRequest;

$result = (new SignatureValidator())->validate(
    WebhookRequest::fromPsr7($serverRequest),   // PSR-7 ServerRequestInterface
    $secret,                                     // the one you persisted at registration
);

if (! $result->valid) {
    // $result->reason is a stable snake_case slug ("signature_mismatch",
    // "stale_timestamp", …) that never contains signing material — safe to log verbatim.
    http_response_code(401);
    exit;
}
```

No PSR-7? Construct `WebhookRequest` directly with the method, the path+query
as sent on the wire, the Host header value, the **exact raw body bytes**
(never re-encoded from a decoded payload — the hash covers bytes, not
meaning), and the three signature headers.

## Token caching

Merchant access tokens are fetched and refreshed automatically; you never
handle them. The default cache is in-memory — one token per process, which is
fine for classic per-request PHP. In long-running or multi-worker setups
(queues, Octane, Swoole), share one token through any PSR-16 cache instead of
letting every worker mint its own:

```php
use Nesthus\Vipps\Auth\Psr16TokenCache;

$vipps = new Vipps(
    $config,
    $client,
    $httpFactory,
    $httpFactory,
    tokenCache: new Psr16TokenCache($yourPsr16Cache),   // Redis, APCu, your framework's store
);
```

If Vipps ever answers 401 on a token that should have been valid (revoked
keys, clock trouble), `$vipps->tokens()->forget()` drops the cached token so
the next call fetches fresh.

## Errors

Everything the SDK throws implements the `Nesthus\Vipps\Exceptions\VippsException`
marker interface.

```php
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsException;

try {
    $vipps->epayment()->capture($reference, $amount, $key);
} catch (VippsApiException $e) {
    $e->status;    // HTTP status; 0 when the transport itself failed (DNS, TLS, timeout)
    $e->details;   // Vipps' decoded error body (problem+json when available)
    $e->traceId;   // quote this in a Vipps support case
} catch (VippsConfigException $e) {
    // a value YOUR code built is invalid — bad reference format, negative
    // amount, empty credential — thrown before any request goes out
}
```

Modules never inspect status codes themselves: any non-2xx from Vipps becomes
a `VippsApiException` at the transport. Its message carries method, path,
status and Vipps' own title/detail — never request headers or bodies, so
credentials can't leak through your exception logs.

## Testing your integration

The suite under [`tests/`](tests) doubles as living documentation of every
endpoint's exact wire shape — URL, headers, JSON body — and the pattern is
portable: a queue-and-record PSR-18 fake
([`tests/Support/FakeHttpClient.php`](tests/Support/FakeHttpClient.php), ~60
lines, copy it into your own suite) plus Guzzle's PSR-17 `HttpFactory`. No
HTTP, no mocking framework; the real transport runs down to the PSR-7
boundary.

```php
use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Epayment\CreatePayment;
use Nesthus\Vipps\Epayment\EpaymentApi;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;

$http = new FakeHttpClient();
$factory = new HttpFactory();

$api = new EpaymentApi(new ApiTransport(
    $http,
    $factory,
    $factory,
    new VippsConfig('client-id', 'client-secret', 'subscription-key', '123456'),
));

$http->queueJson(201, ['reference' => 'order-2026-000123', 'redirectUrl' => 'https://landing.vipps.no?token=abc']);

$created = $api->createPayment(new CreatePayment(
    amount: Amount::fromMajor(49),
    reference: 'order-2026-000123',
    returnUrl: 'https://shop.example/return',
), 'idem-create-1');

$request = $http->lastRequest();   // full PSR-7 request — assert method, URI, Idempotency-Key, body
```

For end-to-end testing against real infrastructure, use Vipps' **apitest**
environment: `Environment::Test` points at `https://apitest.vipps.no`, a full
sandbox with its own merchant keys (from the test tab of the merchant portal's
developer section) and test users. Test keys never work against production and
vice versa.

## Development

No local PHP needed — everything runs in throwaway containers:

```bash
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app composer:2 install
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/pest
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/pint --test src tests
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/phpstan analyse --no-progress
```

CI runs the same three checks (pint, phpstan level max, pest) on PHP 8.3
and 8.4.

## Versioning & license

This is a 0.x release: the public API may still move between minor versions —
pin accordingly and read [CHANGELOG.md](CHANGELOG.md) before upgrading. Once
the surface has survived real-world use, 1.0 freezes it under semantic
versioning.

MIT — see [LICENSE](LICENSE).

Related: [nesthus/vipps-laravel](https://github.com/ekstremedia/vipps-laravel)
wraps this SDK for Laravel (config, container bindings, facades).
