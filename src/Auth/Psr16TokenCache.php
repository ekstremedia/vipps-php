<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

use DateTimeImmutable;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Support\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Bridges TokenProvider to any PSR-16 cache (Redis, APCu, a framework's
 * store), so all workers share one token instead of each minting their own.
 *
 * The default prefix uses dots, never colons: `{}()/\@:` are RESERVED key
 * characters in PSR-16, and strict stores (Symfony's Psr16Cache among them)
 * throw on every operation whose key contains one. The suffix TokenProvider
 * appends is a hex hash, so the prefix is the only part that can go illegal.
 *
 * Keys are also normalized to PSR-16's portable limit: stores are only
 * required to support keys up to 64 characters, and the default prefix plus
 * TokenProvider's 64-char sha256 suffix is 84 — a strict store may reject
 * that on every operation. A key that fits passes through untouched; one
 * that does not keeps the prefix (so entries stay recognizable in the
 * store) and gets its suffix re-hashed down to whatever fits.
 *
 * Stored as a plain array, never a serialized object — a cache shared
 * between deploys must not break when this class changes shape. The entry's
 * TTL matches the token's own expiry, so an evicted-late token can never be
 * returned as valid.
 */
final readonly class Psr16TokenCache implements TokenCacheInterface
{
    /**
     * PSR-16 only obliges implementations to support keys up to this long.
     */
    private const MAX_KEY_LENGTH = 64;

    /**
     * What must remain for the shortened suffix: 20 hex chars = 80 bits,
     * far beyond collision reach for the handful of sales units one
     * application talks to.
     */
    private const MIN_SUFFIX_LENGTH = 20;

    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'nesthus-vipps.token.',
        private ClockInterface $clock = new SystemClock(),
    ) {
        if (strlen($prefix) > self::MAX_KEY_LENGTH - self::MIN_SUFFIX_LENGTH) {
            throw new VippsConfigException(sprintf(
                'Psr16TokenCache prefix must be at most %d characters, so the whole key fits PSR-16\'s %d-character portable limit.',
                self::MAX_KEY_LENGTH - self::MIN_SUFFIX_LENGTH,
                self::MAX_KEY_LENGTH,
            ));
        }
    }

    public function get(string $key): ?AccessToken
    {
        $stored = $this->cache->get($this->storageKey($key));

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
        $ttl = $token->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();

        if ($ttl <= 0) {
            return;
        }

        $this->cache->set($this->storageKey($key), [
            'value' => $token->value(),
            'expires_at' => $token->expiresAt->getTimestamp(),
        ], $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->delete($this->storageKey($key));
    }

    /**
     * Every operation must derive the key the same way, or put() and get()
     * silently talk past each other — hence one private chokepoint.
     */
    private function storageKey(string $key): string
    {
        $full = $this->prefix . $key;

        if (strlen($full) <= self::MAX_KEY_LENGTH) {
            return $full;
        }

        return $this->prefix . substr(hash('sha256', $key), 0, self::MAX_KEY_LENGTH - strlen($this->prefix));
    }
}
