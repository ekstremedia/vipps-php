<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

use DateTimeImmutable;

/**
 * A bearer token with its expiry. Vipps tokens live 1 hour in test and 24
 * hours in production, and may overlap freely — so caching one until close
 * to expiry is always safe.
 */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Fresh means "will still be valid for at least $marginSeconds" — the
     * margin absorbs clock skew and the time the actual API call needs, so a
     * token is never handed out that dies mid-request.
     */
    public function isFreshAt(DateTimeImmutable $now, int $marginSeconds = 60): bool
    {
        return $now->getTimestamp() + $marginSeconds < $this->expiresAt->getTimestamp();
    }
}
