<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Login;

use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\VippsConfig;

/**
 * Vipps Login: a standard OIDC authorization-code flow, discovered at
 * runtime from the well-known document rather than hardcoded paths.
 *
 * Takes the BARE ApiTransport on purpose — OIDC endpoints authenticate with
 * HTTP Basic (token exchange), the user's own bearer token (userinfo), or
 * nothing (discovery); a merchant access token is never correct here.
 *
 * Not `readonly` like its siblings: the discovery document is memoized per
 * instance, so a login flow (authorization URL, then code exchange, then
 * userinfo) costs one discovery fetch, not three.
 */
final class LoginApi
{
    private const DISCOVERY_PATH = '/access-management-1.0/access/.well-known/openid-configuration';

    private ?OpenIdConfiguration $configuration = null;

    public function __construct(
        private readonly ApiTransport $transport,
        private readonly VippsConfig $config,
    ) {}

    public function configuration(): OpenIdConfiguration
    {
        return $this->configuration ??= OpenIdConfiguration::fromArray(
            self::stringKeyed($this->transport->request('GET', self::DISCOVERY_PATH)->data),
        );
    }

    /**
     * The URL to send the user's browser to. Pure URL building — no HTTP
     * beyond the memoized discovery — so it is safe to call while rendering.
     *
     * The discovered authorization endpoint is used verbatim once proven an
     * absolute http(s) URL (the browser goes wherever Vipps says), with the
     * standard authorization-code parameters appended; when the request
     * carries a PKCE verifier, its S256 challenge is derived here so the
     * verifier itself never leaves the caller until the code exchange.
     *
     * @throws VippsMalformedResponseException when discovery published no usable authorization endpoint
     */
    public function buildAuthorizationUrl(AuthorizationRequest $request): string
    {
        $endpoint = $this->configuration()->authorizationEndpoint;

        // Validated here — the one place the URL leaves the SDK verbatim —
        // rather than eagerly in configuration(): the transport-called
        // endpoints are already host-checked in transportPathFor(), and a
        // broken authorization_endpoint must not fail an unrelated userinfo
        // call. The check itself is load-bearing: OpenIdConfiguration maps a
        // missing key to '', and appending the query to '' (or any relative
        // path) yields a RELATIVE redirect the browser resolves against the
        // MERCHANT's own origin — silently sending the user, state, PKCE
        // challenge and all, right back where they came from.
        if ($endpoint === '') {
            throw VippsMalformedResponseException::missingField('OIDC discovery document', 'authorization_endpoint');
        }

        $parts = parse_url($endpoint);
        $isAbsoluteHttpUrl = is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['host'] !== ''
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);

        if (! $isAbsoluteHttpUrl) {
            throw VippsMalformedResponseException::unexpectedValue('OIDC discovery document', 'authorization_endpoint', $endpoint);
        }

        $query = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $request->redirectUri,
            'scope' => implode(' ', $request->scopes),
            'state' => $request->state,
        ];

        if ($request->nonce !== null) {
            $query['nonce'] = $request->nonce;
        }

        if ($request->codeVerifier !== null) {
            $query['code_challenge'] = self::codeChallenge($request->codeVerifier);
            $query['code_challenge_method'] = 'S256';
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code from the redirect for tokens. Form-encoded with HTTP
     * Basic client authentication, per RFC 6749 — the one Vipps endpoint
     * that is not JSON.
     *
     * @param string $redirectUri must be byte-identical to the one in the authorization request
     * @param string|null $codeVerifier the SAME verifier whose challenge went into the authorization URL
     */
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): TokenSet
    {
        $path = $this->transportPathFor($this->configuration()->tokenEndpoint);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        $response = $this->transport->requestForm('POST', $path, $form, [
            'Authorization' => 'Basic ' . base64_encode("{$this->config->clientId}:{$this->config->clientSecret()}"),
        ]);

        $accessToken = $response->data['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            // A 2xx without a token is a contract violation; fromResponse is
            // the only constructor, so hand it the reason as a problem title.
            throw VippsApiException::fromResponse(
                'POST',
                $path,
                $response->status,
                '{"title":"token response is missing access_token"}',
            );
        }

        return TokenSet::fromArray(self::stringKeyed($response->data));
    }

    /**
     * The user's claims, authorized by THEIR access token from exchangeCode()
     * — not the merchant token. Which keys are present (`name`, `email`,
     * `phone_number`, `address`, …) follows the scopes the user actually
     * granted; only `sub` is guaranteed.
     *
     * @return array<string, mixed>
     */
    public function userinfo(string $accessToken): array
    {
        $path = $this->transportPathFor($this->configuration()->userinfoEndpoint);

        $response = $this->transport->request('GET', $path, headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        return self::stringKeyed($response->data);
    }

    /**
     * Discovery hands out ABSOLUTE endpoint URLs while ApiTransport joins
     * baseUrl+path, so endpoints called through the transport are reduced to
     * path+query here — after proving the discovered host IS the configured
     * one. If Vipps ever moves login to its own host, this throws loudly
     * instead of silently gluing that endpoint's path onto the wrong origin.
     */
    private function transportPathFor(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ! isset($parts['host'])) {
            throw new VippsConfigException("Discovered OIDC endpoint \"{$endpoint}\" is not an absolute URL.");
        }

        $base = parse_url($this->config->baseUrl());
        $baseHost = is_array($base) && isset($base['host']) ? $base['host'] : '';

        if (strcasecmp($parts['host'], $baseHost) !== 0) {
            throw new VippsConfigException(
                "Discovered OIDC endpoint \"{$endpoint}\" is not on the configured host \"{$baseHost}\" — refusing to call a different origin. Vipps may have moved login to its own host; this SDK needs updating before that is safe.",
            );
        }

        $path = $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * RFC 7636 §4.2: code_challenge = BASE64URL(SHA256(verifier)), unpadded.
     */
    private static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * ApiResponse::$data is array<mixed>; every consumer here needs string
     * keys, so drop the (contract-violating) rest instead of casting blindly.
     *
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
