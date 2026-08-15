# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(0.x: the public API may still move between minor versions).

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
