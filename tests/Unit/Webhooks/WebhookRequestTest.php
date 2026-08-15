<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Nesthus\Vipps\Webhooks\WebhookRequest;

it('maps a PSR-7 server request onto the fields validation needs', function (): void {
    $body = '{"eventType":"epayments.payment.captured.v1"}';
    $authorization = 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=c2ln';

    $psr7 = new ServerRequest(
        'POST',
        'https://merchant.example.no/hooks/vipps?probe=1',
        [
            'x-ms-date' => 'Sat, 15 Aug 2026 12:00:00 GMT',
            'x-ms-content-sha256' => 'aGFzaA==',
            'Authorization' => $authorization,
            'X-Irrelevant' => 'never part of the signed string',
        ],
        $body,
    );

    $request = WebhookRequest::fromPsr7($psr7);

    expect($request->method)->toBe('POST')
        ->and($request->pathAndQuery)->toBe('/hooks/vipps?probe=1')
        ->and($request->host)->toBe('merchant.example.no')
        ->and($request->rawBody)->toBe($body)
        ->and($request->header('x-ms-date'))->toBe('Sat, 15 Aug 2026 12:00:00 GMT')
        ->and($request->header('x-ms-content-sha256'))->toBe('aGFzaA==')
        ->and($request->header('Authorization'))->toBe($authorization)
        ->and($request->header('x-irrelevant'))->toBeNull();
});

it('keeps a non-default port in the host, as the signer saw it', function (): void {
    $psr7 = new ServerRequest('POST', 'https://merchant.example.no:8443/hooks/vipps', [], '{}');

    expect(WebhookRequest::fromPsr7($psr7)->host)->toBe('merchant.example.no:8443');
});

it('looks headers up case-insensitively', function (): void {
    $request = new WebhookRequest('POST', '/hooks', 'merchant.example.no', '{}', [
        'X-MS-DATE' => 'Sat, 15 Aug 2026 12:00:00 GMT',
        'authorization' => 'HMAC-SHA256 …',
    ]);

    expect($request->header('x-ms-date'))->toBe('Sat, 15 Aug 2026 12:00:00 GMT')
        ->and($request->header('X-Ms-Date'))->toBe('Sat, 15 Aug 2026 12:00:00 GMT')
        ->and($request->header('AUTHORIZATION'))->toBe('HMAC-SHA256 …')
        ->and($request->header('x-ms-content-sha256'))->toBeNull();
});
