<?php

declare(strict_types=1);

use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Login\TokenSet;

/**
 * A compact JWT with the given payload claims and no real signature —
 * exactly the shape idTokenClaims() must be able to read, since it
 * deliberately never verifies signatures.
 *
 * @param array<string, mixed> $claims
 */
function loginUnsignedJwt(array $claims): string
{
    $b64url = fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return $b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
        . '.' . $b64url((string) json_encode($claims))
        . '.not-a-real-signature';
}

it('maps the token response, tolerating extra and missing keys', function () {
    $tokens = TokenSet::fromArray([
        'access_token' => 'user-access-token',
        'id_token' => 'x.y.z',
        'token_type' => 'bearer',
        'expires_in' => 3600,
        'scope' => 'openid name',
        'brand_new_vipps_field' => 'ignored',
    ]);

    expect($tokens->accessToken())->toBe('user-access-token')
        ->and($tokens->idToken())->toBe('x.y.z')
        ->and($tokens->tokenType)->toBe('bearer')
        ->and($tokens->expiresIn)->toBe(3600)
        ->and($tokens->scope)->toBe('openid name');

    $sparse = TokenSet::fromArray(['access_token' => 'user-access-token']);

    expect($sparse->idToken())->toBe('')
        ->and($sparse->tokenType)->toBe('')
        ->and($sparse->expiresIn)->toBe(0)
        ->and($sparse->scope)->toBe('');
});

it('decodes id token claims without verifying the signature', function () {
    $claims = [
        'sub' => 'c06c4afe-d9e1-4c5d-939a-177d752a0944',
        'nonce' => 'nonce-xyz',
        // Multibyte value: exercises base64url characters beyond the ASCII
        // happy path, where a plain base64_decode would trip on -/_.
        'name' => 'Kåre Ærlig Nesthus',
        'iat' => 1755244800,
    ];

    $tokens = TokenSet::fromArray([
        'access_token' => 'user-access-token',
        'id_token' => loginUnsignedJwt($claims),
    ]);

    expect($tokens->idTokenClaims())->toBe($claims);
});

it('redacts both bearer secrets from debug output while keeping non-secrets readable', function () {
    $tokens = TokenSet::fromArray([
        'access_token' => 'top-secret-access-token',
        'id_token' => 'header.top-secret-claims.signature',
        'token_type' => 'bearer',
        'expires_in' => 3600,
        'scope' => 'openid name',
    ]);

    ob_start();
    var_dump($tokens);
    $varDump = (string) ob_get_clean();

    foreach ([print_r($tokens, true), $varDump] as $dump) {
        expect($dump)->not->toContain('top-secret-access-token')
            ->not->toContain('top-secret-claims')
            ->toContain('***redacted***')
            ->toContain('bearer')       // non-secrets stay useful for debugging
            ->toContain('openid name');
    }
});

it('rejects an id token that is not a compact JWT', function () {
    $tokens = TokenSet::fromArray(['id_token' => 'not-a-jwt-at-all']);

    expect(fn() => $tokens->idTokenClaims())
        ->toThrow(VippsConfigException::class, 'not a compact JWT');
});

it('rejects an id token whose payload is not base64url JSON', function () {
    $tokens = TokenSet::fromArray(['id_token' => 'header.!!!not-base64!!!.signature']);

    expect(fn() => $tokens->idTokenClaims())
        ->toThrow(VippsConfigException::class, 'payload segment');
});
