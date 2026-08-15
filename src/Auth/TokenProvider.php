<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

use DateTimeImmutable;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Support\SystemClock;
use Nesthus\Vipps\VippsConfig;
use Psr\Clock\ClockInterface;

/**
 * Exchanges the sales unit's API keys for a short-lived bearer token
 * (POST /accesstoken/get) and caches it until shortly before expiry.
 *
 * Uses the bare ApiTransport on purpose — this is the one endpoint that is
 * authenticated by client_id/client_secret headers instead of a bearer
 * token, which is exactly why authentication is a decorator and not baked
 * into the transport.
 */
final readonly class TokenProvider
{
    public function __construct(
        private ApiTransport $transport,
        private VippsConfig $config,
        private TokenCacheInterface $cache = new InMemoryTokenCache(),
        private ClockInterface $clock = new SystemClock(),
        private int $freshnessMarginSeconds = 60,
    ) {}

    public function token(): string
    {
        $key = $this->cacheKey();

        $cached = $this->cache->get($key);
        if ($cached !== null && $cached->isFreshAt($this->clock->now(), $this->freshnessMarginSeconds)) {
            return $cached->value;
        }

        $fresh = $this->fetch();
        $this->cache->put($key, $fresh);

        return $fresh->value;
    }

    /**
     * Drop the cached token so the next call fetches a fresh one — the
     * recovery path when Vipps answers 401 on a token that should have been
     * valid (revoked keys, clock trouble).
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function fetch(): AccessToken
    {
        $response = $this->transport->request('POST', '/accesstoken/get', headers: [
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ]);

        $token = $response->data['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw VippsApiException::fromResponse('POST', '/accesstoken/get', $response->status, 'token response missing access_token');
        }

        return new AccessToken($token, $this->expiryFrom($response->data));
    }

    /**
     * Vipps sends both `expires_on` (unix timestamp, as a string) and
     * `expires_in` (seconds). Prefer the absolute one — it is immune to the
     * latency between Vipps stamping the response and us reading it.
     *
     * @param array<mixed> $data
     */
    private function expiryFrom(array $data): DateTimeImmutable
    {
        $expiresOn = $data['expires_on'] ?? null;
        if (is_string($expiresOn) && ctype_digit($expiresOn)) {
            return (new DateTimeImmutable())->setTimestamp((int) $expiresOn);
        }
        if (is_int($expiresOn)) {
            return (new DateTimeImmutable())->setTimestamp($expiresOn);
        }

        $expiresIn = $data['expires_in'] ?? null;
        $seconds = match (true) {
            is_string($expiresIn) && ctype_digit($expiresIn) => (int) $expiresIn,
            is_int($expiresIn) => $expiresIn,
            // Unknown shape: treat the token as very short-lived rather than
            // caching something that might already be stale.
            default => 60,
        };

        return $this->clock->now()->modify("+{$seconds} seconds");
    }

    private function cacheKey(): string
    {
        return hash('sha256', implode('|', [
            $this->config->environment->value,
            $this->config->merchantSerialNumber,
            $this->config->clientId,
        ]));
    }
}
