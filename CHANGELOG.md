# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(0.x: the public API may still move between minor versions).

## [0.2.0] - 2026-08-16

### Changed

- **ePayment (breaking)** — `capture()`, `cancel()` and `refund()` return the
  adjusted `Payment` (reference, state, aggregate amounts) instead of `void`.
  Vipps explicitly tells merchants to verify the capture response before
  shipping; discarding the body forced an extra `getPayment()` round trip at
  exactly that point.
- **ePayment (breaking)** — `UserFlow::Native` is now
  `UserFlow::NativeRedirect` with the wire value `NATIVE_REDIRECT`: the
  ePayment API accepts `NATIVE_REDIRECT`, not the `NATIVE` 0.1.0 shipped, so
  app-to-app payment creation always failed validation — nothing working can
  break.
- **Core (breaking)** — `ApiTransport` throws `VippsApiException` for every
  non-2xx status, including 3xx: redirects are never followed (PSR-18 clients
  are not required to), so a 3xx previously came back as a "successful"
  `ApiResponse` carrying a redirect page.
- **Core (breaking)** — a 2xx response whose non-empty body is not valid JSON
  now throws `VippsMalformedResponseException` instead of mapping to empty
  data, which only deferred the contract violation to a confusing
  missing-field error later. Empty bodies (204s, bare-200 mutations) stay
  valid. The body itself stays out of the message.
- **Core (breaking)** — `Psr16TokenCache` normalizes storage keys to PSR-16's
  64-character portable limit (the default prefix plus TokenProvider's sha256
  suffix was 84, which strict stores may reject outright); a key that does
  not fit keeps its prefix and gets the suffix re-hashed down to fit. Entries
  under the old long keys are orphaned — tokens refetch on their own. Custom
  prefixes longer than 44 characters are now rejected
  (`VippsConfigException`).
- **Core** — `composer.json` declares the `ext-mbstring` requirement
  `SystemInfo` already had: without the extension, header generation died
  with an undefined-function error instead of a clear install-time message.

- **Core (breaking)** — `Psr16TokenCache`'s default key prefix changed from
  `nesthus-vipps:token:` to `nesthus-vipps.token.`: `:` is a RESERVED key
  character in PSR-16 (`{}()/\@:`), so strict stores (e.g. Symfony's
  `Psr16Cache`) threw on every operation. Entries under the old prefix are
  simply orphaned — tokens refetch on their own. The class also takes an
  optional PSR-20 `ClockInterface`, so TTL math no longer reads `time()`
  directly.

- **Recurring (breaking)** — `Charge` now follows the v3 contract for money
  totals and failure details: captured/refunded/cancelled amounts live in the
  required `Charge::$summary` (`ChargeSummary`) instead of the v2-only
  top-level `amountRefunded` (which v3 never sends, so refund guards read
  null forever), and the failure pair is `failureReason` +
  `failureDescription` — v3 has no `failureCode`.
- **Recurring (breaking)** — `captureCharge()` requires an `Amount`: v3's
  capture request has a required `amount` even for a full capture (the old
  empty-body "full capture" violated the spec), and the deprecated
  `description` field is no longer sent.
- **Recurring (breaking)** — `listCharges()` returns a `ChargePage`
  (`charges` + `continuationToken`) and accepts the previous page's token,
  because v3 pages this endpoint through `Continuation-Token` headers.
- **Recurring (breaking)** — `NewCharge` validates `due`/`retryDays` against
  the effective charge type: RECURRING (the default) requires both,
  UNSCHEDULED forbids `due` and permits `retryDays` only omitted or `0` (the
  spec's rule — there is no due date to retry after), and omits null fields
  from the payload — previously an UNSCHEDULED charge was unrepresentable.
  A `due` string must also be a real calendar date: `2026-02-30` used to
  match the shape check and go on the wire.
- **Recurring (breaking)** — `Agreement::$interval` and
  `NewAgreement::$interval` are now nullable, for FLEXIBLE agreements only:
  the v3 flexible model has no fixed cadence, so the field is optional there
  (and enforced everywhere else — a null interval with LEGACY/VARIABLE
  pricing throws `VippsConfigException`, a non-FLEXIBLE response without one
  throws `VippsMalformedResponseException`).

### Fixed

- **ePayment** — `CreatedPayment::fromArray()` throws
  `VippsMalformedResponseException` when the create-payment response is
  missing its `reference`, instead of returning an object whose empty
  reference could never address the payment again.
- **ePayment** — `CreatePayment` rejects `UserFlow::PushMessage` without a
  `customerPhoneNumber` at construction (`VippsConfigException`): the API
  requires `customer` for that flow, and the push has nowhere to go without
  it.
- **Core** — `VippsConfig` rejects a `baseUrlOverride` carrying userinfo, a
  query or a fragment: `ApiTransport` appends API paths verbatim (so
  query/fragment displace every path), and embedded credentials would leak
  through `__debugInfo()`. A plain path prefix stays allowed.
- **Core** — `AuthenticatedTransport` strips caller-supplied `Authorization`
  headers case-insensitively before adding its Bearer token; a lowercase
  `authorization` previously survived the merge and silently replaced the
  Bearer via PSR-7's case-insensitive `withHeader()`.
- **Core** — `ApiTransport` maps an unencodable JSON payload (e.g. invalid
  UTF-8 in a caller-supplied description) to `VippsConfigException` instead of
  leaking a bare `JsonException`; the payload itself never appears in the
  message.
- **Core** — `AuthenticatedTransport` no longer "retries" a 401 that came
  from the token endpoint itself: bad keys now fail after exactly one token
  request instead of a doomed second fetch with the same keys. The
  single-retry behavior for a revoked bearer on the original request is
  unchanged.
- **Core** — `TokenProvider`'s cache key now includes the resolved base URL,
  so a config pointed at a mock server via `baseUrlOverride` no longer shares
  cached tokens with the real host.
- **Core** — `VippsConfig` validates `baseUrlOverride` with a real URL parse
  (http/https scheme + host required); the old `str_starts_with('http')`
  check accepted values like `httpfoo://…`.
- **Core** — `Vipps::login()` memoizes its `LoginApi` like the other lazies;
  a fresh instance per call silently defeated the per-instance OIDC discovery
  memoization.
- **Login** — the OAuth form body is joined with a literal `&` regardless of
  the host's `arg_separator.output` ini setting.
- **Webhooks** — closed the 0.1.0 "webhook secret encoding unconfirmed"
  limitation: Vipps' official request-authentication sample keys HMAC-SHA256
  with the secret's raw UTF-8 bytes (verified 2026-08-15) — exactly what
  `SignatureValidator` already does. Comment-only; no behavior change.
- **Recurring** — a 2xx body missing `status`, `summary` or `chargeId`, or
  carrying an unknown agreement/charge status or pricing type, now throws
  `VippsMalformedResponseException` instead of a bare `ValueError` (unknown
  status), an empty string (missing `chargeId`) or a silent LEGACY relabel
  (unknown pricing type).
- **Recurring** — response mapping no longer papers over malformed money and
  identity fields: a missing charge `amount` used to become a valid-looking
  zero `Amount`, a missing or malformed `currency` was silently relabelled
  `NOK` (an invalid `SEK` price became an apparently valid NOK one), an
  unrepresentable `interval` defaulted to monthly, and missing
  `agreementId`/`vippsConfirmationUrl` (`CreatedAgreement`) or
  `id`/`productName` (`Agreement`) became empty strings. All now throw
  `VippsMalformedResponseException`.
- **Recurring** — `getChargeById()`'s docs claimed Vipps grants the by-id
  route *higher* rate limits and recommended it for webhook handlers; the
  spec says the opposite — it is an investigation aid and explicitly not a
  replacement for `getCharge()`. Comment/README-only; no behavior change.

- **ePayment** — `Payment::fromArray()` now throws
  `VippsMalformedResponseException` (a `VippsException`) when a 2xx body is
  missing `state` or carries a state this SDK does not know, instead of
  letting a native `ValueError` escape the documented throw surface.
- **Login** — `buildAuthorizationUrl()` refuses a discovery document whose
  `authorization_endpoint` is missing or not an absolute http(s) URL
  (`VippsMalformedResponseException`), instead of silently building a
  relative redirect that the browser would resolve against the merchant's
  own origin.

### Added

- **ePayment** — `CreatePayment` validates `customerPhoneNumber` at
  construction as a bare MSISDN (digits only, country code included, no plus
  sign) — the same rule `NewAgreement.phoneNumber` already enforced for the
  identical wire concept.
- **Recurring** — `listAgreements()` takes optional `pageNumber`/`pageSize`;
  `CreatedAgreement::$chargeId` exposes the initial charge's id (set when the
  `NewAgreement` carried an `initialCharge` — this response is the only
  convenient place to learn it); `PricingType::Flexible` (v3's third pricing
  model) and `Pricing::$maxAmount` (the ceiling the customer actually
  approved) are now mapped.
- **Recurring** — `Pricing::flexible(string $currency)` creates FLEXIBLE
  agreement requests, previously unrepresentable: per the v3 spec the pricing
  payload is `type` + `currency` only (no amount, no ceiling — the user
  approves no price up front), and `interval:` may be `null`.
- **Recurring** — `NewAgreement` rejects an `initialCharge` whose `Amount`
  currency differs from the pricing currency: the initial-charge payload
  carries no currency of its own, so Vipps would read the minor units in the
  pricing currency — the right number in the wrong currency.

### Security

- **Core (breaking)** — secrets moved from public properties to methods
  backed by the engine's `SensitiveParameterValue`:
  `VippsConfig->clientSecret`/`->subscriptionKey` → `clientSecret()`/
  `subscriptionKey()`, `AccessToken->value` → `value()`,
  `RegisteredWebhook->secret` → `secret()`. `__debugInfo()` covers
  `var_dump()`/`print_r()`, but `var_export()` ignores it — the wrapper is
  what keeps a secret out of all three dump functions (and out of
  `serialize()`, which it refuses). Constructor parameters carry
  `#[SensitiveParameter]`, so stack traces redact them too; non-secret fields
  stay readable in dumps.
- **Login** — `TokenSet` marks `accessToken`/`idToken` as
  `#[SensitiveParameter]` and redacts both from `var_dump`/`print_r` output
  via `__debugInfo()`; non-secret fields stay readable.

## [0.1.0] - 2026-08-15

### Added

- **Access tokens** — automatic fetching of merchant bearer tokens
  (`POST /accesstoken/get`) with expiry-aware caching behind
  `TokenCacheInterface`: `InMemoryTokenCache` (default, per process) and
  `Psr16TokenCache` (share one token across workers via any PSR-16 store),
  plus `TokenProvider::forget()` as the 401 recovery path.
- **Recurring API v3** — `createAgreement`, `listAgreements` (with status
  filter), `getAgreement`, `updateAgreement`, `stopAgreement`; `createCharge`,
  `listCharges`, `getCharge`, `getChargeById` (the higher-rate-limit by-id
  route), `cancelCharge`, `captureCharge` (full or partial, for
  `RESERVE_CAPTURE`), `refundCharge`. Request DTOs (`NewAgreement`,
  `NewCharge`, `AgreementPatch`, `InitialCharge`, `Pricing`, `Interval`)
  validate at construction; enums for status, pricing type, interval unit and
  transaction type.
- **ePayment API v1** — `createPayment`, `getPayment`, `getEvents` (the full
  audit trail), `capture`, `cancel`, `refund`. Reserve-then-capture semantics
  surfaced as `PaymentState` plus aggregate amounts on `Payment`;
  `CreatePayment` validates the merchant reference (8–64 chars of
  `[a-zA-Z0-9-]`) before any request goes out; `UserFlow` covers
  `WEB_REDIRECT`, `NATIVE`, `PUSH_MESSAGE` and `QR`.
- **Login (OIDC)** — runtime discovery from the well-known document,
  `buildAuthorizationUrl` (state required, optional nonce, S256 PKCE derived
  from a caller-held verifier), `exchangeCode` (form-encoded with HTTP Basic
  client auth, per RFC 6749), `userinfo` (authorized by the user's own token),
  and `TokenSet::idTokenClaims()` for tokens received directly from the token
  endpoint.
- **Webhooks** — management API (`register`, `all`, `delete`; the signing
  secret is returned exactly once at registration) and a framework-free
  `SignatureValidator` for inbound deliveries: recomputed content hash,
  replay-window check on `x-ms-date`, constant-time comparisons, fail-closed
  on missing/malformed headers, and leak-free reason slugs in
  `ValidationResult`. `WebhookRequest::fromPsr7()` for PSR-7 receivers.
- **Core** — `Vipps` entry point wiring PSR-18/PSR-17 plumbing once;
  `VippsConfig` + `Environment` (apitest/production hosts) + `SystemInfo`
  (`Vipps-System-*` headers); `Amount` value object over integer minor units
  (floats refused by design); every mutating call requires a caller-supplied
  idempotency key; exceptions `VippsException` (marker), `VippsApiException`
  (status, decoded details, traceId — never headers or bodies in the message)
  and `VippsConfigException`.

### Known limitations

- **No JWKS id_token verification.** `TokenSet::idTokenClaims()` decodes the
  id token without checking its signature — safe only because the token
  arrives over TLS directly from the token endpoint in a confidential-client
  exchange. Tokens from any other channel (browser redirects, mobile apps,
  other services) must be verified with a real JWT library.
- **Webhook secret encoding unconfirmed.** `SignatureValidator` HMACs with the
  secret's raw bytes. Azure APIM samples typically base64-decode the signing
  key first; whether the secret Vipps returns needs that decode is pending
  verification against the first real sandbox delivery. Getting it wrong shows
  up as `signature_mismatch` on otherwise well-formed requests.

[0.1.0]: https://github.com/ekstremedia/vipps-php/releases/tag/v0.1.0
