<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Http;

/**
 * What every API module talks to. Two implementations: the bare
 * ApiTransport (credential headers, JSON, error mapping) and
 * AuthenticatedTransport, which decorates it with a Bearer token. Modules
 * type-hint this interface so tests can substitute either.
 */
interface Transport
{
    /**
     * @param array<string, mixed>|null $json request body, encoded as JSON when non-null
     * @param array<string, string> $headers extra headers for this one call
     * @param string|null $idempotencyKey caller-supplied Idempotency-Key header. The SDK
     *                                    never invents one: an idempotency key only protects
     *                                    against retries when the CALLER persists it next to
     *                                    its own record before the request goes out.
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): ApiResponse;
}
