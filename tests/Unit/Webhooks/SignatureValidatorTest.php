<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Nesthus\Vipps\Webhooks\SignatureValidator;
use Nesthus\Vipps\Webhooks\WebhookRequest;
use Psr\Clock\ClockInterface;

function webhookFrozenClock(DateTimeImmutable $moment): ClockInterface
{
    return new class ($moment) implements ClockInterface {
        public function __construct(private readonly DateTimeImmutable $moment) {}

        public function now(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

/**
 * Signs the parts exactly the way Vipps (Azure APIM) does, so tests exercise
 * the real algorithm end to end instead of stubbing intermediate values.
 *
 * @return array<string, string>
 */
function webhookSignedHeaders(
    string $secret,
    string $date,
    string $body,
    string $method = 'POST',
    string $pathAndQuery = '/hooks/vipps?probe=1',
    string $host = 'merchant.example.no',
): array {
    $contentHash = base64_encode(hash('sha256', $body, true));
    $signedString = strtoupper($method) . "\n" . $pathAndQuery . "\n" . $date . ';' . $host . ';' . $contentHash;
    $signature = base64_encode(hash_hmac('sha256', $signedString, $secret, true));

    return [
        'x-ms-date' => $date,
        'x-ms-content-sha256' => $contentHash,
        'Authorization' => 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $signature,
    ];
}

/**
 * A legitimate delivery signed at $signedAt with the given secret.
 */
function webhookValidDelivery(DateTimeImmutable $signedAt, string $secret, string $body = '{"eventType":"epayments.payment.captured.v1"}'): WebhookRequest
{
    $headers = webhookSignedHeaders($secret, $signedAt->format(DateTimeInterface::RFC7231), $body);

    return new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $headers);
}

it('accepts a fully valid signed request', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';

    $result = (new SignatureValidator(webhookFrozenClock($now)))
        ->validate(webhookValidDelivery($now, $secret), $secret);

    expect($result->valid)->toBeTrue()
        ->and($result->reason)->toBeNull();
});

it('accepts headers regardless of their casing', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';

    $upperCased = [];
    foreach (webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body) as $name => $value) {
        $upperCased[strtoupper($name)] = $value;
    }

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $upperCased);

    expect((new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret)->valid)->toBeTrue();
});

it('rejects a tampered body', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), '{"amount":100}');

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', '{"amount":99900}', $headers);
    $result = (new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('content_hash_mismatch');
});

it('rejects a tampered body even when the content hash header is updated to match it', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), '{"amount":100}');

    // The attacker's move against a validator that trusts the header: change
    // the body AND recompute x-ms-content-sha256. The signature still covers
    // the original hash, so this must die at the signature step instead.
    $tamperedBody = '{"amount":99900}';
    $headers['x-ms-content-sha256'] = base64_encode(hash('sha256', $tamperedBody, true));

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $tamperedBody, $headers);
    $result = (new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('signature_mismatch');
});

it('rejects the wrong secret', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');

    $result = (new SignatureValidator(webhookFrozenClock($now)))
        ->validate(webhookValidDelivery($now, 'whsec-test-secret-value'), 'whsec-a-different-secret');

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('signature_mismatch');
});

it('rejects an empty secret without hashing anything', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');

    $result = (new SignatureValidator(webhookFrozenClock($now)))
        ->validate(webhookValidDelivery($now, 'whsec-test-secret-value'), '');

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('empty_secret');
});

it('rejects a stale x-ms-date beyond the skew window', function (): void {
    $signedAt = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $clock = webhookFrozenClock($signedAt->modify('+301 seconds'));

    $result = (new SignatureValidator($clock))->validate(webhookValidDelivery($signedAt, $secret), $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('stale_timestamp');
});

it('accepts a request aged exactly at the skew boundary', function (): void {
    $signedAt = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $clock = webhookFrozenClock($signedAt->modify('+300 seconds'));

    expect((new SignatureValidator($clock))->validate(webhookValidDelivery($signedAt, $secret), $secret)->valid)
        ->toBeTrue();
});

it('accepts a future-dated request within the skew window', function (): void {
    $signedAt = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $clock = webhookFrozenClock($signedAt->modify('-200 seconds'));

    expect((new SignatureValidator($clock))->validate(webhookValidDelivery($signedAt, $secret), $secret)->valid)
        ->toBeTrue();
});

it('rejects a future-dated request beyond the skew window', function (): void {
    $signedAt = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $clock = webhookFrozenClock($signedAt->modify('-301 seconds'));

    $result = (new SignatureValidator($clock))->validate(webhookValidDelivery($signedAt, $secret), $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('stale_timestamp');
});

it('honours a custom skew window', function (): void {
    $signedAt = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $clock = webhookFrozenClock($signedAt->modify('+30 seconds'));

    $result = (new SignatureValidator($clock, maxSkewSeconds: 10))
        ->validate(webhookValidDelivery($signedAt, $secret), $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('stale_timestamp');
});

it('rejects a malformed x-ms-date', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';

    // Signed consistently over the garbage date, so only the date parse fails.
    $headers = webhookSignedHeaders($secret, 'not-a-date', $body);
    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $headers);

    $result = (new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('malformed_date_header');
});

it('fails closed when a required header is missing', function (string $header, string $expectedReason): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body);
    unset($headers[$header]);

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $headers);
    $result = (new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe($expectedReason);
})->with([
    'content hash' => ['x-ms-content-sha256', 'missing_content_hash_header'],
    'date' => ['x-ms-date', 'missing_date_header'],
    'authorization' => ['Authorization', 'missing_or_malformed_authorization_header'],
]);

it('parses the signature regardless of authorization parameter order', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body);

    $signature = explode('&', substr($headers['Authorization'], strpos($headers['Authorization'], 'Signature=') + strlen('Signature=')), 2)[0];
    $headers['Authorization'] = 'HMAC-SHA256 Signature=' . $signature . '&SignedHeaders=x-ms-date;host;x-ms-content-sha256';

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $headers);

    expect((new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret)->valid)->toBeTrue();
});

it('rejects an authorization header without a signature component', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body);
    $headers['Authorization'] = 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256';

    $request = new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, $headers);
    $result = (new SignatureValidator(webhookFrozenClock($now)))->validate($request, $secret);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('missing_or_malformed_authorization_header');
});

it('validates a PSR-7 server request end to end via fromPsr7', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-test-secret-value';
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body);

    $psr7 = new ServerRequest('POST', 'https://merchant.example.no/hooks/vipps?probe=1', $headers, $body);

    $result = (new SignatureValidator(webhookFrozenClock($now)))
        ->validate(WebhookRequest::fromPsr7($psr7), $secret);

    expect($result->valid)->toBeTrue()
        ->and($result->reason)->toBeNull();
});

it('never leaks the secret or any signature material in a failure reason', function (): void {
    $now = new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    $secret = 'whsec-SUPER-SECRET-material';
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $headers = webhookSignedHeaders($secret, $now->format(DateTimeInterface::RFC7231), $body);
    $signature = explode('&', substr($headers['Authorization'], strpos($headers['Authorization'], 'Signature=') + strlen('Signature=')), 2)[0];

    $delivery = webhookValidDelivery($now, $secret, $body);
    $validator = new SignatureValidator(webhookFrozenClock($now));

    $failures = [
        $validator->validate(new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', '{"tampered":1}', $headers), $secret),
        $validator->validate($delivery, 'whsec-a-different-secret'),
        (new SignatureValidator(webhookFrozenClock($now->modify('+1 hour'))))->validate($delivery, $secret),
        $validator->validate(new WebhookRequest('POST', '/hooks/vipps?probe=1', 'merchant.example.no', $body, []), $secret),
        $validator->validate($delivery, ''),
    ];

    foreach ($failures as $result) {
        expect($result->valid)->toBeFalse()
            ->and($result->reason)->not->toBeNull()
            ->and($result->reason)->not->toContain($secret)
            ->and($result->reason)->not->toContain($signature)
            ->and($result->reason)->toMatch('/^[a-z_]+$/');
    }
});
