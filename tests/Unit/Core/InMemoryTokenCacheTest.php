<?php

declare(strict_types=1);

use Nesthus\Vipps\Auth\AccessToken;
use Nesthus\Vipps\Auth\InMemoryTokenCache;

beforeEach(function () {
    $this->cache = new InMemoryTokenCache();
    $this->token = new AccessToken('t-1', new DateTimeImmutable('+1 hour'));
});

it('misses on an unknown key', function () {
    expect($this->cache->get('nope'))->toBeNull();
});

it('round-trips a token', function () {
    $this->cache->put('key', $this->token);

    expect($this->cache->get('key'))->toBe($this->token);
});

it('keeps keys isolated', function () {
    $this->cache->put('a', $this->token);

    expect($this->cache->get('b'))->toBeNull();
});

it('overwrites on a repeated put', function () {
    $newer = new AccessToken('t-2', new DateTimeImmutable('+2 hours'));

    $this->cache->put('key', $this->token);
    $this->cache->put('key', $newer);

    expect($this->cache->get('key'))->toBe($newer);
});

it('forgets a stored token', function () {
    $this->cache->put('key', $this->token);
    $this->cache->forget('key');

    expect($this->cache->get('key'))->toBeNull();
});

it('tolerates forgetting a key that was never stored', function () {
    $this->cache->forget('nope');

    expect($this->cache->get('nope'))->toBeNull();
});
