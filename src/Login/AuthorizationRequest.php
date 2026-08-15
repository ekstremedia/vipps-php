<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Login;

use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * Everything the caller decides about one login attempt. Validated at
 * construction so a bad value fails in the merchant's code, not as a cryptic
 * error page on Vipps' side after the redirect.
 *
 * PKCE is opt-in via $codeVerifier: the SDK derives the S256 challenge for
 * the authorization URL, but generating AND PERSISTING the verifier is the
 * caller's job — it must survive in the session until the redirect returns,
 * or the code exchange cannot include it.
 */
final readonly class AuthorizationRequest
{
    public const DEFAULT_SCOPES = ['openid', 'name', 'email', 'phoneNumber'];

    /**
     * @param string $state CSRF binding between this request and the redirect
     *                      back: generate an unguessable value, store it in
     *                      the user's session, and REFUSE the callback unless
     *                      the returned `state` matches. The SDK cannot do
     *                      this check — it has no session.
     * @param list<string> $scopes granted claims surface in userinfo(); `openid` is what makes it OIDC at all
     * @param string|null $nonce echoed into the id token's `nonce` claim, for callers who bind tokens to sessions
     * @param string|null $codeVerifier RFC 7636 code verifier: 43–128 chars of [A-Za-z0-9-._~]
     */
    public function __construct(
        public string $redirectUri,
        public string $state,
        public array $scopes = self::DEFAULT_SCOPES,
        public ?string $nonce = null,
        public ?string $codeVerifier = null,
    ) {
        if (trim($redirectUri) === '') {
            throw new VippsConfigException('redirectUri is required — it must exactly match a redirect URI registered in the merchant portal.');
        }

        if (trim($state) === '') {
            throw new VippsConfigException('state is required: it is the CSRF binding for the whole flow, so an empty value defeats it.');
        }

        if ($scopes === []) {
            throw new VippsConfigException('scopes cannot be empty — at minimum "openid" is required for an OIDC flow.');
        }

        if ($codeVerifier !== null && preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier) !== 1) {
            throw new VippsConfigException('codeVerifier must be 43–128 characters of [A-Za-z0-9-._~], per RFC 7636 §4.1.');
        }
    }
}
