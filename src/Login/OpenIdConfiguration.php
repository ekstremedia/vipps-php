<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Login;

/**
 * The OIDC discovery document (/.well-known/openid-configuration), reduced to
 * the five URLs this SDK acts on. Endpoint URLs are kept exactly as Vipps
 * published them — absolute — because the authorization endpoint is handed to
 * a browser as-is; only the endpoints called through the transport are
 * converted to paths (and host-checked) at the call site in LoginApi.
 */
final readonly class OpenIdConfiguration
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public string $jwksUri,
    ) {}

    /**
     * Tolerates missing keys (empty string) and ignores the many other fields
     * of a discovery document — Vipps adds fields without notice.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: self::stringAt($data, 'issuer'),
            authorizationEndpoint: self::stringAt($data, 'authorization_endpoint'),
            tokenEndpoint: self::stringAt($data, 'token_endpoint'),
            userinfoEndpoint: self::stringAt($data, 'userinfo_endpoint'),
            jwksUri: self::stringAt($data, 'jwks_uri'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringAt(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
