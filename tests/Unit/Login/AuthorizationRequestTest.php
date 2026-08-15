<?php

declare(strict_types=1);

use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Login\AuthorizationRequest;

it('defaults to the standard Vipps Login scopes', function () {
    $request = new AuthorizationRequest('https://example.test/callback', 'state-abc');

    expect($request->scopes)->toBe(['openid', 'name', 'email', 'phoneNumber'])
        ->and($request->nonce)->toBeNull()
        ->and($request->codeVerifier)->toBeNull();
});

it('accepts code verifiers at the RFC 7636 length bounds', function () {
    $shortest = str_repeat('a', 43);
    $longest = str_repeat('a', 120) . '-._~AZ09';

    expect((new AuthorizationRequest('https://example.test/cb', 's', codeVerifier: $shortest))->codeVerifier)->toBe($shortest)
        ->and((new AuthorizationRequest('https://example.test/cb', 's', codeVerifier: $longest))->codeVerifier)->toBe($longest);
});

it('rejects a code verifier shorter than 43 characters', function () {
    expect(fn() => new AuthorizationRequest('https://example.test/cb', 's', codeVerifier: str_repeat('a', 42)))
        ->toThrow(VippsConfigException::class, 'RFC 7636');
});

it('rejects a code verifier longer than 128 characters', function () {
    expect(fn() => new AuthorizationRequest('https://example.test/cb', 's', codeVerifier: str_repeat('a', 129)))
        ->toThrow(VippsConfigException::class, 'RFC 7636');
});

it('rejects a code verifier with characters outside the RFC 7636 alphabet', function () {
    expect(fn() => new AuthorizationRequest('https://example.test/cb', 's', codeVerifier: str_repeat('a', 42) . '!'))
        ->toThrow(VippsConfigException::class, 'RFC 7636');
});

it('requires a non-empty state', function () {
    expect(fn() => new AuthorizationRequest('https://example.test/cb', '  '))
        ->toThrow(VippsConfigException::class, 'state is required');
});

it('requires a non-empty redirect URI', function () {
    expect(fn() => new AuthorizationRequest('', 'state-abc'))
        ->toThrow(VippsConfigException::class, 'redirectUri is required');
});

it('requires at least one scope', function () {
    expect(fn() => new AuthorizationRequest('https://example.test/cb', 'state-abc', scopes: []))
        ->toThrow(VippsConfigException::class, 'scopes cannot be empty');
});
