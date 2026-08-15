<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Auth\TokenProvider;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Http\AuthenticatedTransport;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\Tests\Support\FrozenClock;
use Nesthus\Vipps\VippsConfig;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    $this->client = new FakeHttpClient();
    $factory = new HttpFactory();
    $config = new VippsConfig('client-id', 'client-secret', 'sub-key', '123456');
    $inner = new ApiTransport($this->client, $factory, $factory, $config);

    $this->transport = new AuthenticatedTransport(
        $inner,
        new TokenProvider($inner, $config, clock: FrozenClock::at('2026-08-15 12:00:00')),
    );

    // Both the token exchange and the API call flow through the same fake
    // client, so tests distinguish them by path.
    $this->tokenFetches = fn(): array => array_values(array_filter(
        $this->client->requests,
        fn(RequestInterface $r): bool => str_ends_with($r->getUri()->getPath(), '/accesstoken/get'),
    ));
    $this->apiCalls = fn(): array => array_values(array_filter(
        $this->client->requests,
        fn(RequestInterface $r): bool => ! str_ends_with($r->getUri()->getPath(), '/accesstoken/get'),
    ));
});

it('adds a Bearer token from the TokenProvider', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(200, ['ok' => true]);

    $response = $this->transport->request('GET', '/epayment/v1/payments/abc');

    expect($response->data)->toBe(['ok' => true])
        ->and($this->client->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer token-1');
});

it('reuses the cached token across calls', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(200)
        ->queueJson(200);

    $this->transport->request('GET', '/a');
    $this->transport->request('GET', '/b');

    expect(($this->tokenFetches)())->toHaveCount(1)
        ->and($this->client->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer token-1');
});

it('lets its own Bearer win over a caller-supplied Authorization header', function () {
    // The `+` merge keeps the left (Bearer) entry when both sides define the
    // key — this decorator exists to own authentication, so a stray
    // Authorization in per-call headers must not silently disable it.
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(200);

    $this->transport->request('GET', '/x', headers: ['Authorization' => 'Basic xyz']);

    expect($this->client->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer token-1');
});

it('forgets the cached token and retries exactly once on 401', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(401, ['title' => 'Unauthorized'])
        ->queueJson(200, ['access_token' => 'token-2', 'expires_in' => 3600])
        ->queueJson(200, ['ok' => true]);

    $response = $this->transport->request('POST', '/x', ['a' => 1], idempotencyKey: 'key-1');

    expect($response->data)->toBe(['ok' => true])
        ->and(($this->tokenFetches)())->toHaveCount(2);

    [$first, $retry] = ($this->apiCalls)();
    expect($first->getHeaderLine('Authorization'))->toBe('Bearer token-1')
        ->and($retry->getHeaderLine('Authorization'))->toBe('Bearer token-2')
        ->and($retry->getHeaderLine('Idempotency-Key'))->toBe('key-1')
        ->and((string) $retry->getBody())->toBe('{"a":1}');
});

it('propagates a second consecutive 401 instead of retrying forever', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(401, ['title' => 'Unauthorized'])
        ->queueJson(200, ['access_token' => 'token-2', 'expires_in' => 3600])
        ->queueJson(401, ['title' => 'Still unauthorized']);

    try {
        $this->transport->request('GET', '/x');
        $this->fail('Expected VippsApiException was not thrown.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(401)
            ->and($e->getMessage())->toContain('Still unauthorized');
    }

    expect(($this->apiCalls)())->toHaveCount(2);
});

it('propagates a 401 from the token endpoint itself — no doomed second fetch', function () {
    // Bad keys: /accesstoken/get answers 401 before any API call happens.
    // Retrying would refetch with the very same keys, so exactly ONE token
    // request may go out and the original request must never be sent.
    $this->client->queueJson(401, ['title' => 'Unauthorized']);

    try {
        $this->transport->request('GET', '/x');
        $this->fail('Expected VippsApiException was not thrown.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(401)
            ->and($e->getMessage())->toContain('/accesstoken/get');
    }

    expect(($this->tokenFetches)())->toHaveCount(1)
        ->and(($this->apiCalls)())->toHaveCount(0);
});

it('propagates a 401 from the token refetch inside the retry — no third attempt', function () {
    // The original request 401s (token revoked), and the refetch then hits
    // rotated-away keys: that second token 401 must surface as-is.
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(401, ['title' => 'Unauthorized'])
        ->queueJson(401, ['title' => 'Keys revoked']);

    try {
        $this->transport->request('GET', '/x');
        $this->fail('Expected VippsApiException was not thrown.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(401)
            ->and($e->getMessage())->toContain('/accesstoken/get');
    }

    expect(($this->tokenFetches)())->toHaveCount(2)
        ->and(($this->apiCalls)())->toHaveCount(1);
});

it('propagates non-401 errors without a retry', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(500, ['title' => 'Internal Server Error']);

    try {
        $this->transport->request('GET', '/x');
        $this->fail('Expected VippsApiException was not thrown.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(500);
    }

    expect(($this->apiCalls)())->toHaveCount(1)
        ->and(($this->tokenFetches)())->toHaveCount(1);
});
