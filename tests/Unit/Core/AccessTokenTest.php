<?php

declare(strict_types=1);

use Nesthus\Vipps\Auth\AccessToken;

it('is fresh while the margin still fits before expiry', function () {
    $now = new DateTimeImmutable('2026-08-15 12:00:00');
    $token = new AccessToken('t', $now->modify('+100 seconds'));

    expect($token->isFreshAt($now, marginSeconds: 99))->toBeTrue();
});

it('is stale exactly when now + margin reaches expiry', function () {
    // Strict `<`: a token that dies at the same second the margin runs out
    // cannot be trusted with a call.
    $now = new DateTimeImmutable('2026-08-15 12:00:00');
    $token = new AccessToken('t', $now->modify('+100 seconds'));

    expect($token->isFreshAt($now, marginSeconds: 100))->toBeFalse();
});

it('is stale at and after its expiry even with no margin', function () {
    $expiry = new DateTimeImmutable('2026-08-15 12:00:00');
    $token = new AccessToken('t', $expiry);

    expect($token->isFreshAt($expiry, marginSeconds: 0))->toBeFalse()
        ->and($token->isFreshAt($expiry->modify('+1 second'), marginSeconds: 0))->toBeFalse();
});

it('defaults to a 60 second margin', function () {
    $now = new DateTimeImmutable('2026-08-15 12:00:00');

    expect((new AccessToken('t', $now->modify('+61 seconds')))->isFreshAt($now))->toBeTrue()
        ->and((new AccessToken('t', $now->modify('+60 seconds')))->isFreshAt($now))->toBeFalse();
});
