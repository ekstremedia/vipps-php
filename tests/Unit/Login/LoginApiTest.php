<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Login\AuthorizationRequest;
use Nesthus\Vipps\Login\LoginApi;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;

/**
 * @return array{LoginApi, FakeHttpClient}
 */
function loginTestApi(): array
{
    $http = new FakeHttpClient();
    $factory = new HttpFactory();
    $config = new VippsConfig(
        clientId: 'client-id',
        clientSecret: 'client-secret',
        subscriptionKey: 'subscription-key',
        merchantSerialNumber: '123456',
    );

    return [new LoginApi(new ApiTransport($http, $factory, $factory, $config), $config), $http];
}

/**
 * @return array<string, string>
 */
function loginDiscoveryDocument(string $host = 'https://apitest.vipps.no'): array
{
    return [
        'issuer' => $host . '/access-management-1.0/access/',
        'authorization_endpoint' => $host . '/access-management-1.0/access/oauth2/auth',
        'token_endpoint' => $host . '/access-management-1.0/access/oauth2/token',
        'userinfo_endpoint' => $host . '/vipps-userinfo-api/userinfo',
        'jwks_uri' => $host . '/access-management-1.0/access/.well-known/jwks.json',
    ];
}

it('fetches the discovery document once and memoizes it', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument());

    $first = $login->configuration();
    $second = $login->configuration();

    expect($http->requests)->toHaveCount(1)
        ->and($http->lastRequest()->getMethod())->toBe('GET')
        ->and((string) $http->lastRequest()->getUri())
        ->toBe('https://apitest.vipps.no/access-management-1.0/access/.well-known/openid-configuration')
        ->and($second)->toBe($first)
        ->and($first->issuer)->toBe('https://apitest.vipps.no/access-management-1.0/access/')
        ->and($first->authorizationEndpoint)->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/auth')
        ->and($first->tokenEndpoint)->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/token')
        ->and($first->userinfoEndpoint)->toBe('https://apitest.vipps.no/vipps-userinfo-api/userinfo')
        ->and($first->jwksUri)->toBe('https://apitest.vipps.no/access-management-1.0/access/.well-known/jwks.json');
});

it('reuses the memoized discovery across endpoint calls', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument())
        ->queueJson(200, ['access_token' => 'user-token', 'id_token' => 'x.y.', 'token_type' => 'bearer', 'expires_in' => 3600, 'scope' => 'openid'])
        ->queueJson(200, ['sub' => 'user-123']);

    $login->exchangeCode('the-code', 'https://example.test/callback');
    $login->userinfo('user-token');

    expect($http->requests)->toHaveCount(3); // discovery + exchange + userinfo, never a second discovery
});

it('refuses a discovered endpoint on a different host', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument('https://login.vipps.no'));

    expect(fn() => $login->exchangeCode('the-code', 'https://example.test/callback'))
        ->toThrow(VippsConfigException::class, 'login.vipps.no');

    expect($http->requests)->toHaveCount(1); // discovery only — nothing was sent to the wrong origin
});

it('builds the authorization URL with the standard code-flow parameters', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument());

    $url = $login->buildAuthorizationUrl(new AuthorizationRequest(
        redirectUri: 'https://example.test/callback',
        state: 'state-abc',
        nonce: 'nonce-xyz',
        // The RFC 7636 appendix B reference verifier, so the derived
        // challenge below is the spec's own published value.
        codeVerifier: 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    ));

    [$base, $queryString] = explode('?', $url, 2);
    parse_str($queryString, $query);

    expect($base)->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/auth')
        ->and($query)->toBe([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => 'https://example.test/callback',
            'scope' => 'openid name email phoneNumber',
            'state' => 'state-abc',
            'nonce' => 'nonce-xyz',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ])
        ->and($http->requests)->toHaveCount(1); // discovery only — URL building is pure
});

it('refuses a discovery document without an authorization endpoint', function () {
    [$login, $http] = loginTestApi();
    $document = loginDiscoveryDocument();
    unset($document['authorization_endpoint']);
    $http->queueJson(200, $document);

    // The trap this guards: OpenIdConfiguration maps the missing key to '',
    // and '' + '?…' is a RELATIVE URL that would redirect the user — OAuth
    // params and all — to the merchant's own origin instead of Vipps.
    expect(fn() => $login->buildAuthorizationUrl(new AuthorizationRequest(
        redirectUri: 'https://example.test/callback',
        state: 'state-abc',
    )))->toThrow(VippsMalformedResponseException::class, 'authorization_endpoint');
});

it('refuses an authorization endpoint that is not an absolute http(s) URL', function (string $endpoint) {
    [$login, $http] = loginTestApi();
    $document = loginDiscoveryDocument();
    $document['authorization_endpoint'] = $endpoint;
    $http->queueJson(200, $document);

    expect(fn() => $login->buildAuthorizationUrl(new AuthorizationRequest(
        redirectUri: 'https://example.test/callback',
        state: 'state-abc',
    )))->toThrow(VippsMalformedResponseException::class, 'authorization_endpoint');
})->with([
    'relative path' => ['/access-management-1.0/access/oauth2/auth'],
    'schemeless' => ['apitest.vipps.no/oauth2/auth'],
    'non-http scheme' => ['javascript:alert(1)'],
    'scheme without host' => ['https://'],
]);

it('omits nonce and PKCE parameters when not requested', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument());

    $url = $login->buildAuthorizationUrl(new AuthorizationRequest(
        redirectUri: 'https://example.test/callback',
        state: 'state-abc',
        scopes: ['openid', 'email'],
    ));

    parse_str(explode('?', $url, 2)[1], $query);

    expect($query)->toBe([
        'response_type' => 'code',
        'client_id' => 'client-id',
        'redirect_uri' => 'https://example.test/callback',
        'scope' => 'openid email',
        'state' => 'state-abc',
    ]);
});

it('sends the code exchange as a Basic-authenticated form post', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument())
        ->queueJson(200, [
            'access_token' => 'user-access-token',
            'id_token' => 'aGVhZGVy.cGF5bG9hZA.',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'openid name',
        ]);

    $tokens = $login->exchangeCode(
        'the-code',
        'https://example.test/callback',
        codeVerifier: 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    );

    $request = $http->lastRequest();
    parse_str((string) $request->getBody(), $form);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/token')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and($request->getHeaderLine('Authorization'))->toBe('Basic ' . base64_encode('client-id:client-secret'))
        ->and($form)->toBe([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => 'https://example.test/callback',
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
        ])
        ->and($tokens->accessToken())->toBe('user-access-token')
        ->and($tokens->idToken())->toBe('aGVhZGVy.cGF5bG9hZA.')
        ->and($tokens->tokenType)->toBe('bearer')
        ->and($tokens->expiresIn)->toBe(3600)
        ->and($tokens->scope)->toBe('openid name');
});

it('leaves code_verifier out of the exchange when the flow did not use PKCE', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument())
        ->queueJson(200, ['access_token' => 'user-access-token']);

    $login->exchangeCode('the-code', 'https://example.test/callback');

    parse_str((string) $http->lastRequest()->getBody(), $form);

    expect($form)->not->toHaveKey('code_verifier');
});

it('throws when the token response is missing access_token', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument())
        ->queueJson(200, ['token_type' => 'bearer']);

    expect(fn() => $login->exchangeCode('the-code', 'https://example.test/callback'))
        ->toThrow(VippsApiException::class, 'access_token');
});

it('calls userinfo with the user\'s own bearer token', function () {
    [$login, $http] = loginTestApi();
    $http->queueJson(200, loginDiscoveryDocument())
        ->queueJson(200, [
            'sub' => 'c06c4afe-d9e1-4c5d-939a-177d752a0944',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone_number' => '4712345678',
        ]);

    $claims = $login->userinfo('user-access-token');

    $request = $http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/vipps-userinfo-api/userinfo')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer user-access-token')
        ->and($claims)->toBe([
            'sub' => 'c06c4afe-d9e1-4c5d-939a-177d752a0944',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone_number' => '4712345678',
        ]);
});

it('keeps a discovered query string when reducing an endpoint to a transport path', function () {
    [$login, $http] = loginTestApi();
    $document = loginDiscoveryDocument();
    $document['userinfo_endpoint'] = 'https://apitest.vipps.no/vipps-userinfo-api/userinfo?schema=openid';
    $http->queueJson(200, $document)->queueJson(200, ['sub' => 'user-123']);

    $login->userinfo('user-access-token');

    expect((string) $http->lastRequest()->getUri())
        ->toBe('https://apitest.vipps.no/vipps-userinfo-api/userinfo?schema=openid');
});
