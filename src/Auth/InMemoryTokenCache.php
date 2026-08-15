<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

/**
 * Default cache: per-process. Fine for a CLI job or a single request cycle;
 * long-running apps should wire Psr16TokenCache to something shared so every
 * worker does not fetch its own token.
 */
final class InMemoryTokenCache implements TokenCacheInterface
{
    /** @var array<string, AccessToken> */
    private array $tokens = [];

    public function get(string $key): ?AccessToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function put(string $key, AccessToken $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
