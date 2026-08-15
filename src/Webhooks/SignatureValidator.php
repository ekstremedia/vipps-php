<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

use Nesthus\Vipps\Support\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Verifies that an inbound webhook delivery was really signed by Vipps
 * MobilePay — the check every integrator gets wrong, so the sharp edges are
 * spelled out here.
 *
 * The scheme is Azure API Management HMAC, verified against
 * https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/request-authentication/
 * (2026-08-15): `x-ms-date` (RFC 1123 HTTP-date), `x-ms-content-sha256`
 * (base64 SHA-256 of the raw body) and
 * `Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=<base64>`,
 * where the signature is HMAC-SHA256 over
 * `"{METHOD}\n{pathAndQuery}\n{x-ms-date};{host};{contentHash}"` (method
 * uppercase, no trailing newline).
 *
 * Discipline this class enforces, in order:
 *
 *   - The content hash is RECOMPUTED from the raw body, never trusted from
 *     the request's own `x-ms-content-sha256` header — otherwise an attacker
 *     tampers the body and simply updates that header to match, defeating
 *     the point of hashing it at all.
 *   - `x-ms-date` must be within $maxSkewSeconds of now, in EITHER direction
 *     (clock skew runs both ways). A tight window is safe: every Vipps retry
 *     is a freshly signed request, so rejecting stale timestamps never drops
 *     a legitimate redelivery — it only bounds replay attacks.
 *   - Every comparison is hash_equals(), never `===`.
 *   - Fail closed on any missing or malformed header.
 *   - Failure reasons are bare slugs: the secret, the received signature and
 *     the computed signature never appear in a ValidationResult, so the
 *     merchant can log reasons verbatim without leaking signing material.
 *
 * OPEN QUESTION — key encoding. This HMACs with the secret's raw bytes
 * as-is. Azure APIM's own samples typically base64-decode the signing key
 * first; if the secret Vipps returns at registration is itself base64 (common
 * for APIM-issued keys), a decode step would be needed here. Deliberately
 * kept as raw-string until proven otherwise — verify against the first real
 * sandbox delivery, where getting it wrong shows up as `signature_mismatch`
 * on an otherwise well-formed request.
 */
final readonly class SignatureValidator
{
    public function __construct(
        private ClockInterface $clock = new SystemClock(),
        private int $maxSkewSeconds = 300,
    ) {}

    public function validate(WebhookRequest $request, string $secret): ValidationResult
    {
        if ($secret === '') {
            // An empty key would make the HMAC below trivially forgeable —
            // treat "not configured yet" as reject, never as pass-through.
            return ValidationResult::invalid('empty_secret');
        }

        $contentHashHeader = $request->header('x-ms-content-sha256');
        if ($contentHashHeader === null || $contentHashHeader === '') {
            return ValidationResult::invalid('missing_content_hash_header');
        }

        $contentHash = base64_encode(hash('sha256', $request->rawBody, true));
        if (! hash_equals($contentHash, $contentHashHeader)) {
            return ValidationResult::invalid('content_hash_mismatch');
        }

        $dateHeader = $request->header('x-ms-date');
        if ($dateHeader === null || $dateHeader === '') {
            return ValidationResult::invalid('missing_date_header');
        }

        $timestamp = strtotime($dateHeader);
        if ($timestamp === false) {
            return ValidationResult::invalid('malformed_date_header');
        }

        if (abs($this->clock->now()->getTimestamp() - $timestamp) > $this->maxSkewSeconds) {
            return ValidationResult::invalid('stale_timestamp');
        }

        $provided = $this->extractSignature($request->header('authorization'));
        if ($provided === null) {
            return ValidationResult::invalid('missing_or_malformed_authorization_header');
        }

        // The signed string embeds the hash RECOMPUTED above (identical to
        // the header at this point, but sourced from the body), so the
        // signature transitively covers the exact bytes received.
        $signedString = strtoupper($request->method) . "\n"
            . $request->pathAndQuery . "\n"
            . $dateHeader . ';' . $request->host . ';' . $contentHash;

        $expected = base64_encode(hash_hmac('sha256', $signedString, $secret, true));

        if (! hash_equals($expected, $provided)) {
            return ValidationResult::invalid('signature_mismatch');
        }

        return ValidationResult::valid();
    }

    /**
     * Pulls the base64 signature out of
     * `HMAC-SHA256 SignedHeaders=...&Signature=<base64>`. Extraction
     * terminates at the next `&` (if any) rather than assuming `Signature=`
     * is last: every sample so far puts it last, but nothing guarantees APIM
     * never reorders or appends components, and trailing garbage would make
     * the comparison fail closed even for a legitimate request.
     */
    private function extractSignature(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        $pos = strpos($authorizationHeader, 'Signature=');
        if ($pos === false) {
            return null;
        }

        $rest = substr($authorizationHeader, $pos + strlen('Signature='));
        $signature = explode('&', $rest, 2)[0];

        return $signature !== '' ? $signature : null;
    }
}
