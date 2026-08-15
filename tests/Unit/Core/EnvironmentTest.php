<?php

declare(strict_types=1);

use Nesthus\Vipps\Environment;

it('points Test at the MT sandbox host', function () {
    expect(Environment::Test->baseUrl())->toBe('https://apitest.vipps.no');
});

it('points Production at the live host', function () {
    expect(Environment::Production->baseUrl())->toBe('https://api.vipps.no');
});

it('is backed by stable string values', function () {
    // The backing values feed cache keys (TokenProvider::cacheKey), so
    // renaming them silently invalidates every cached token.
    expect(Environment::Test->value)->toBe('test')
        ->and(Environment::Production->value)->toBe('production');
});
