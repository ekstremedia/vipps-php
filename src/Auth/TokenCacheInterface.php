<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

/**
 * Where TokenProvider parks tokens between calls. The key encodes sales unit
 * + environment, so one cache can serve several configured Vipps instances
 * without cross-contamination.
 */
interface TokenCacheInterface
{
    public function get(string $key): ?AccessToken;

    public function put(string $key, AccessToken $token): void;

    public function forget(string $key): void;
}
