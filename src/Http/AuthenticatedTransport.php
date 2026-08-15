<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Http;

use Nesthus\Vipps\Auth\TokenProvider;
use Nesthus\Vipps\Exceptions\VippsApiException;

/**
 * ApiTransport + `Authorization: Bearer …`. One extra behavior: a single
 * retry after a 401, with the cached token dropped first — a 401 on a token
 * we believed fresh means it was revoked out from under us (key rotation in
 * the portal), and refetching once fixes that case without hiding a real
 * credential problem (the retry's 401 propagates).
 */
final readonly class AuthenticatedTransport implements Transport
{
    public function __construct(
        private ApiTransport $inner,
        private TokenProvider $tokens,
    ) {}

    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): ApiResponse {
        try {
            return $this->send($method, $path, $json, $headers, $idempotencyKey);
        } catch (VippsApiException $e) {
            if ($e->status !== 401) {
                throw $e;
            }

            $this->tokens->forget();

            return $this->send($method, $path, $json, $headers, $idempotencyKey);
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $json, array $headers, ?string $idempotencyKey): ApiResponse
    {
        return $this->inner->request(
            $method,
            $path,
            $json,
            ['Authorization' => 'Bearer ' . $this->tokens->token()] + $headers,
            $idempotencyKey,
        );
    }
}
