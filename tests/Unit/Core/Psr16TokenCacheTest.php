<?php

declare(strict_types=1);

use Nesthus\Vipps\Auth\AccessToken;
use Nesthus\Vipps\Auth\Psr16TokenCache;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Tests\Support\FrozenClock;
use Psr\SimpleCache\CacheInterface;

beforeEach(function () {
    // Minimal array-backed PSR-16 store that records TTLs, so the tests can
    // assert what the bridge hands the cache — not just what comes back.
    $this->store = new class implements CacheInterface {
        /** @var array<string, mixed> */
        public array $items = [];

        /** @var array<string, DateInterval|int|null> */
        public array $ttls = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->items[$key] = $value;
            $this->ttls[$key] = $ttl;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key], $this->ttls[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->items = [];
            $this->ttls = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->get($key, $default);
            }

            return $result;
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }

            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            foreach ($keys as $key) {
                $this->delete($key);
            }

            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->items);
        }
    };

    $this->clock = FrozenClock::at('2026-08-15 12:00:00');
    $this->cache = new Psr16TokenCache($this->store, clock: $this->clock);
});

it('round-trips a token as a plain array under the prefixed key', function () {
    $expiresAt = $this->clock->now()->modify('+3600 seconds');

    $this->cache->put('key', new AccessToken('t-1', $expiresAt));

    // Stored as a plain array, never a serialized object — a shared cache
    // must survive this class changing shape between deploys.
    expect($this->store->items)->toHaveKey('nesthus-vipps.token.key')
        ->and($this->store->items['nesthus-vipps.token.key'])->toBe([
            'value' => 't-1',
            'expires_at' => $expiresAt->getTimestamp(),
        ]);

    $roundTripped = $this->cache->get('key');
    expect($roundTripped)->toBeInstanceOf(AccessToken::class)
        ->and($roundTripped->value())->toBe('t-1')
        ->and($roundTripped->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp());
});

it('never emits a PSR-16 reserved key character, even with the default prefix', function () {
    // The old default prefix was 'nesthus-vipps:token:' — and ':' is on
    // PSR-16's reserved list `{}()/\@:`, so strict stores (e.g. Symfony's
    // Psr16Cache) threw on EVERY operation. This stub enforces the spec the
    // way those stores do; the suffix mirrors what TokenProvider appends
    // (a sha256 hex digest, always legal).
    $strictStore = new class implements CacheInterface {
        /** @var array<string, mixed> */
        public array $items = [];

        public function get(string $key, mixed $default = null): mixed
        {
            $this->assertLegal($key);

            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->assertLegal($key);
            $this->items[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            $this->assertLegal($key);
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->items = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            $this->assertLegal($key);

            return array_key_exists($key, $this->items);
        }

        private function assertLegal(string $key): void
        {
            if (strpbrk($key, '{}()/\\@:') !== false) {
                throw new class ("PSR-16 reserved character in cache key \"{$key}\"") extends InvalidArgumentException implements Psr\SimpleCache\InvalidArgumentException {};
            }
        }
    };

    $cache = new Psr16TokenCache($strictStore, clock: $this->clock);
    $key = hash('sha256', 'anything');

    $cache->put($key, new AccessToken('t-1', $this->clock->now()->modify('+1 hour')));

    expect($cache->get($key))->toBeInstanceOf(AccessToken::class);

    $cache->forget($key);

    expect($cache->get($key))->toBeNull();
});

it('gives the entry a TTL matching the token lifetime exactly', function () {
    // Exact, not a range: put() reads the injected clock, never time().
    $this->cache->put('key', new AccessToken('t-1', $this->clock->now()->modify('+100 seconds')));

    expect($this->store->ttls['nesthus-vipps.token.key'])->toBe(100);
});

it('never stores an already-expired token — the TTL would be negative', function () {
    $this->cache->put('past', new AccessToken('t-1', $this->clock->now()->modify('-10 seconds')));
    $this->cache->put('now', new AccessToken('t-2', $this->clock->now()));

    expect($this->store->items)->toBe([]);
});

it('measures the TTL against the injected clock, not the wall clock', function () {
    $this->clock->advance(500);

    $this->cache->put('key', new AccessToken('t-1', $this->clock->now()->modify('+100 seconds')));

    expect($this->store->ttls['nesthus-vipps.token.key'])->toBe(100);
});

it('misses on an unknown key', function () {
    expect($this->cache->get('nope'))->toBeNull();
});

it('returns null for a malformed cache payload instead of exploding', function (mixed $payload) {
    $this->store->items['nesthus-vipps.token.key'] = $payload;

    expect($this->cache->get('key'))->toBeNull();
})->with([
    'a bare string' => ['not-an-array'],
    'an integer' => [42],
    'missing expires_at' => [['value' => 't-1']],
    'missing value' => [['expires_at' => 1755000000]],
    'value not a string' => [['value' => 42, 'expires_at' => 1755000000]],
    'expires_at not an int' => [['value' => 't-1', 'expires_at' => '1755000000']],
    'null value entry' => [['value' => null, 'expires_at' => null]],
]);

it('forgets by deleting the prefixed key', function () {
    $this->cache->put('key', new AccessToken('t-1', $this->clock->now()->modify('+1 hour')));

    $this->cache->forget('key');

    expect($this->store->items)->toBe([])
        ->and($this->cache->get('key'))->toBeNull();
});

it('honors a custom prefix', function () {
    $cache = new Psr16TokenCache($this->store, prefix: 'other.', clock: $this->clock);

    $cache->put('key', new AccessToken('t-1', $this->clock->now()->modify('+1 hour')));

    expect($this->store->items)->toHaveKey('other.key')
        ->and($cache->get('key'))->toBeInstanceOf(AccessToken::class);
});

it('keeps every generated key within PSR-16\'s 64-character portable limit', function () {
    // TokenProvider's suffix is a 64-char sha256 digest; with the 20-char
    // default prefix the naive concatenation is 84 — longer than the only
    // key length PSR-16 obliges a store to support.
    $key = hash('sha256', 'production|https://api.vipps.no|123456|client-id');

    $this->cache->put($key, new AccessToken('t-1', $this->clock->now()->modify('+1 hour')));

    $seen = array_keys($this->store->items);
    expect($seen)->toHaveCount(1);
    foreach ($seen as $storageKey) {
        expect(strlen((string) $storageKey))->toBeLessThanOrEqual(64)
            ->and((string) $storageKey)->toStartWith('nesthus-vipps.token.');
    }

    // get() and forget() must derive the same shortened key, or they would
    // silently talk past the entry put() just wrote.
    expect($this->cache->get($key)?->value())->toBe('t-1');
    $this->cache->forget($key);
    expect($this->store->items)->toBe([]);
});

it('round-trips and forgets under a shortened key, and distinct long keys stay distinct', function () {
    $keyA = hash('sha256', 'sales-unit-a');
    $keyB = hash('sha256', 'sales-unit-b');

    $this->cache->put($keyA, new AccessToken('t-a', $this->clock->now()->modify('+1 hour')));
    $this->cache->put($keyB, new AccessToken('t-b', $this->clock->now()->modify('+1 hour')));

    expect($this->cache->get($keyA)?->value())->toBe('t-a')
        ->and($this->cache->get($keyB)?->value())->toBe('t-b')
        ->and($this->store->items)->toHaveCount(2);

    $this->cache->forget($keyA);

    expect($this->cache->get($keyA))->toBeNull()
        ->and($this->cache->get($keyB)?->value())->toBe('t-b');
});

it('rejects a prefix too long to leave room for a usable suffix', function () {
    new Psr16TokenCache($this->store, prefix: str_repeat('p', 45), clock: $this->clock);
})->throws(VippsConfigException::class, 'prefix');

it('accepts a prefix at the exact boundary', function () {
    $cache = new Psr16TokenCache($this->store, prefix: str_repeat('p', 44), clock: $this->clock);
    $key = hash('sha256', 'anything');

    $cache->put($key, new AccessToken('t-1', $this->clock->now()->modify('+1 hour')));

    $storageKey = array_keys($this->store->items)[0];
    expect(strlen((string) $storageKey))->toBe(64)
        ->and($cache->get($key)?->value())->toBe('t-1');
});
