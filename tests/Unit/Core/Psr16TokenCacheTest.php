<?php

declare(strict_types=1);

use Nesthus\Vipps\Auth\AccessToken;
use Nesthus\Vipps\Auth\Psr16TokenCache;
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

    $this->cache = new Psr16TokenCache($this->store);
});

it('round-trips a token as a plain array under the prefixed key', function () {
    $expiresAt = (new DateTimeImmutable())->modify('+3600 seconds');

    $this->cache->put('key', new AccessToken('t-1', $expiresAt));

    // Stored as a plain array, never a serialized object — a shared cache
    // must survive this class changing shape between deploys.
    expect($this->store->items)->toHaveKey('nesthus-vipps:token:key')
        ->and($this->store->items['nesthus-vipps:token:key'])->toBe([
            'value' => 't-1',
            'expires_at' => $expiresAt->getTimestamp(),
        ]);

    $roundTripped = $this->cache->get('key');
    expect($roundTripped)->toBeInstanceOf(AccessToken::class)
        ->and($roundTripped->value)->toBe('t-1')
        ->and($roundTripped->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp());
});

it('gives the entry a TTL matching the token lifetime', function () {
    $this->cache->put('key', new AccessToken('t-1', (new DateTimeImmutable())->modify('+100 seconds')));

    $ttl = $this->store->ttls['nesthus-vipps:token:key'];
    expect($ttl)->toBeInt()
        ->and($ttl)->toBeGreaterThan(97) // allow for time() ticking mid-test
        ->and($ttl)->toBeLessThanOrEqual(100);
});

it('never stores an already-expired token — the TTL would be negative', function () {
    $this->cache->put('past', new AccessToken('t-1', (new DateTimeImmutable())->modify('-10 seconds')));
    $this->cache->put('now', new AccessToken('t-2', new DateTimeImmutable()));

    expect($this->store->items)->toBe([]);
});

it('misses on an unknown key', function () {
    expect($this->cache->get('nope'))->toBeNull();
});

it('returns null for a malformed cache payload instead of exploding', function (mixed $payload) {
    $this->store->items['nesthus-vipps:token:key'] = $payload;

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
    $this->cache->put('key', new AccessToken('t-1', (new DateTimeImmutable())->modify('+1 hour')));

    $this->cache->forget('key');

    expect($this->store->items)->toBe([])
        ->and($this->cache->get('key'))->toBeNull();
});

it('honors a custom prefix', function () {
    $cache = new Psr16TokenCache($this->store, prefix: 'other:');

    $cache->put('key', new AccessToken('t-1', (new DateTimeImmutable())->modify('+1 hour')));

    expect($this->store->items)->toHaveKey('other:key')
        ->and($cache->get('key'))->toBeInstanceOf(AccessToken::class);
});
