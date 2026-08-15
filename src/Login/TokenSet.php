<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Login;

use Nesthus\Vipps\Exceptions\VippsConfigException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The token endpoint's answer to a successful code exchange. The id token is
 * kept as the raw compact JWT — see idTokenClaims() for why it can be read
 * without verifying its signature here, and only here.
 *
 * Both tokens are bearer secrets: whoever holds them IS the user. They are
 * methods over SensitiveParameterValue, not public properties, because
 * __debugInfo() alone only covers var_dump()/print_r() — var_export()
 * ignores it — so the raw values must not sit in properties at all.
 * #[SensitiveParameter] keeps them out of stack traces the same way.
 */
final readonly class TokenSet
{
    private SensitiveParameterValue $accessToken;

    private SensitiveParameterValue $idToken;

    public function __construct(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        string $idToken,
        public string $tokenType,
        public int $expiresIn,
        public string $scope,
    ) {
        $this->accessToken = new SensitiveParameterValue($accessToken);
        $this->idToken = new SensitiveParameterValue($idToken);
    }

    /**
     * Same convention as VippsConfig: secrets show as ***redacted***,
     * non-secrets stay readable so a dump is still useful for debugging.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '***redacted***',
            'idToken' => '***redacted***',
            'tokenType' => $this->tokenType,
            'expiresIn' => $this->expiresIn,
            'scope' => $this->scope,
        ];
    }

    /**
     * Tolerates missing keys and ignores extras — Vipps adds fields without
     * notice. Presence of `access_token` is asserted by LoginApi before this
     * mapper runs, so an empty value here means the caller built the array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiresIn = $data['expires_in'] ?? null;

        return new self(
            accessToken: self::stringAt($data, 'access_token'),
            idToken: self::stringAt($data, 'id_token'),
            tokenType: self::stringAt($data, 'token_type'),
            expiresIn: is_int($expiresIn) ? $expiresIn : 0,
            scope: self::stringAt($data, 'scope'),
        );
    }

    public function accessToken(): string
    {
        /** @var string $token */
        $token = $this->accessToken->getValue();

        return $token;
    }

    public function idToken(): string
    {
        /** @var string $token */
        $token = $this->idToken->getValue();

        return $token;
    }

    /**
     * The id token's claims, base64url-decoded WITHOUT verifying the JWT
     * signature.
     *
     * That is safe for exactly one reason: this token arrived over TLS
     * directly from Vipps' token endpoint in a confidential-client code
     * exchange, so its origin is already authenticated by the channel. An id
     * token received from anywhere else — a browser redirect, a mobile app,
     * another service — MUST NOT be trusted this way; it could be forged
     * freely. Full JWKS signature verification is a deliberate non-goal of
     * v0.1 (documented limitation), so if you need to accept tokens from
     * untrusted channels, bring a real JWT library.
     *
     * @return array<string, mixed>
     */
    public function idTokenClaims(): array
    {
        $segments = explode('.', $this->idToken());
        if (count($segments) < 2) {
            throw new VippsConfigException('idToken is not a compact JWT: expected dot-separated segments.');
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if ($payload === false || ! json_validate($payload)) {
            throw new VippsConfigException('idToken payload segment is not base64url-encoded JSON.');
        }

        $claims = [];
        foreach ((array) json_decode($payload, true) as $name => $value) {
            if (is_string($name)) {
                $claims[$name] = $value;
            }
        }

        return $claims;
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
