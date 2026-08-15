<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Auth\InMemoryTokenCache;
use Nesthus\Vipps\Auth\TokenProvider;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\Tests\Support\FrozenClock;
use Nesthus\Vipps\VippsConfig;

beforeEach(function () {
    $this->client = new FakeHttpClient();
    $this->clock = FrozenClock::at('2026-08-15 12:00:00');

    $factory = new HttpFactory();
    $config = new VippsConfig('client-id', 'client-secret', 'sub-key', '123456');
    $transport = new ApiTransport($this->client, $factory, $factory, $config);

    $this->makeProvider = fn(int $margin = 60): TokenProvider => new TokenProvider(
        $transport,
        $config,
        clock: $this->clock,
        freshnessMarginSeconds: $margin,
    );
});

it('posts to /accesstoken/get with the key pair as headers, not a bearer', function () {
    $this->client->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600]);

    $token = ($this->makeProvider)()->token();

    $request = $this->client->lastRequest();
    expect($token)->toBe('token-1')
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/accesstoken/get')
        ->and($request->getHeaderLine('client_id'))->toBe('client-id')
        ->and($request->getHeaderLine('client_secret'))->toBe('client-secret')
        ->and($request->hasHeader('Authorization'))->toBeFalse();
});

it('caches the token — a second call makes no HTTP request', function () {
    $this->client->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600]);
    $provider = ($this->makeProvider)();

    expect($provider->token())->toBe('token-1')
        ->and($provider->token())->toBe('token-1')
        ->and($this->client->requests)->toHaveCount(1);
});

it('honors expires_on as a numeric string over expires_in', function () {
    $this->client->queueJson(200, [
        'access_token' => 'token-1',
        'expires_on' => (string) ($this->clock->now()->getTimestamp() + 3600),
        // A misleading expires_in: if this won, the token would already be
        // stale after the advance below.
        'expires_in' => 1,
    ]);
    $provider = ($this->makeProvider)();

    $provider->token();
    $this->clock->advance(600);

    expect($provider->token())->toBe('token-1')
        ->and($this->client->requests)->toHaveCount(1);
});

it('falls back to expires_in when expires_on is absent', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(200, ['access_token' => 'token-2', 'expires_in' => 3600]);
    $provider = ($this->makeProvider)();

    $provider->token();

    $this->clock->advance(3000); // 3000 + 60s margin < 3600 — still fresh
    expect($provider->token())->toBe('token-1')
        ->and($this->client->requests)->toHaveCount(1);

    $this->clock->advance(541); // 3541 + 60s margin > 3600 — stale now
    expect($provider->token())->toBe('token-2')
        ->and($this->client->requests)->toHaveCount(2);
});

it('treats an unknown expiry shape as roughly 60 seconds', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1'])
        ->queueJson(200, ['access_token' => 'token-2']);
    $provider = ($this->makeProvider)(margin: 10);

    $provider->token();

    $this->clock->advance(30); // 30 + 10s margin < 60 — still fresh
    expect($provider->token())->toBe('token-1')
        ->and($this->client->requests)->toHaveCount(1);

    $this->clock->advance(25); // 55 + 10s margin > 60 — stale now
    expect($provider->token())->toBe('token-2')
        ->and($this->client->requests)->toHaveCount(2);
});

it('refetches a token that expires inside the freshness margin', function () {
    // Expires in 30 s, margin 60 s: valid, but not for long enough to trust
    // with an API call — so even the very next token() refetches.
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 30])
        ->queueJson(200, ['access_token' => 'token-2', 'expires_in' => 30]);
    $provider = ($this->makeProvider)(margin: 60);

    expect($provider->token())->toBe('token-1')
        ->and($provider->token())->toBe('token-2')
        ->and($this->client->requests)->toHaveCount(2);
});

it('forget() drops the cache so the next call fetches fresh', function () {
    $this->client
        ->queueJson(200, ['access_token' => 'token-1', 'expires_in' => 3600])
        ->queueJson(200, ['access_token' => 'token-2', 'expires_in' => 3600]);
    $provider = ($this->makeProvider)();

    expect($provider->token())->toBe('token-1');

    $provider->forget();

    expect($provider->token())->toBe('token-2')
        ->and($this->client->requests)->toHaveCount(2);
});

it('scopes the cache entry to the exact base URL — an override never shares a token with the real host', function () {
    // Same environment, MSN and client id; only baseUrlOverride differs.
    // Without baseUrl() in the cache key these two would be one entry, and a
    // token minted by a local mock server would be replayed against apitest.
    $cache = new InMemoryTokenCache();
    $factory = new HttpFactory();

    $providerFor = fn(VippsConfig $config): TokenProvider => new TokenProvider(
        new ApiTransport($this->client, $factory, $factory, $config),
        $config,
        cache: $cache,
        clock: $this->clock,
    );

    $real = $providerFor(new VippsConfig('client-id', 'client-secret', 'sub-key', '123456'));
    $mock = $providerFor(new VippsConfig('client-id', 'client-secret', 'sub-key', '123456', baseUrlOverride: 'http://localhost:8080'));

    $this->client
        ->queueJson(200, ['access_token' => 'real-token', 'expires_in' => 3600])
        ->queueJson(200, ['access_token' => 'mock-token', 'expires_in' => 3600]);

    expect($real->token())->toBe('real-token')
        ->and($mock->token())->toBe('mock-token')   // a shared entry would replay 'real-token' with no second fetch
        ->and($this->client->requests)->toHaveCount(2);
});

it('throws when the response has no access_token', function () {
    $this->client->queueJson(200, ['token_type' => 'Bearer']);

    try {
        ($this->makeProvider)()->token();
        $this->fail('Expected VippsApiException was not thrown.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(200)
            ->and($e->getMessage())->toContain('/accesstoken/get');
    }
});

it('treats an empty-string access_token as malformed too', function () {
    $this->client->queueJson(200, ['access_token' => '']);

    ($this->makeProvider)()->token();
})->throws(VippsApiException::class);
