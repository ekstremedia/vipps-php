<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * Bridges TokenProvider to any PSR-16 cache (Redis, APCu, a framework's
 * store), so all workers share one token instead of each minting their own.
 *
 * Stored as a plain array, never a serialized object — a cache shared
 * between deploys must not break when this class changes shape. The entry's
 * TTL matches the token's own expiry, so an evicted-late token can never be
 * returned as valid.
 */
final readonly class Psr16TokenCache implements TokenCacheInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'nesthus-vipps:token:',
    ) {}

    public function get(string $key): ?AccessToken
    {
        $stored = $this->cache->get($this->prefix . $key);

        if (! is_array($stored) || ! is_string($stored['value'] ?? null) || ! is_int($stored['expires_at'] ?? null)) {
            return null;
        }

        return new AccessToken(
            $stored['value'],
            (new DateTimeImmutable())->setTimestamp($stored['expires_at']),
        );
    }

    public function put(string $key, AccessToken $token): void
    {
        $ttl = $token->expiresAt->getTimestamp() - time();

        if ($ttl <= 0) {
            return;
        }

        $this->cache->set($this->prefix . $key, [
            'value' => $token->value,
            'expires_at' => $token->expiresAt->getTimestamp(),
        ], $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->delete($this->prefix . $key);
    }
}
