<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Http;

/**
 * A successful (2xx) Vipps API response, already JSON-decoded. Error
 * responses never reach the caller as a value — they throw
 * VippsApiException in the transport, so API classes only handle the happy
 * shape.
 */
final readonly class ApiResponse
{
    /**
     * @param array<mixed> $data decoded JSON body; empty array for empty bodies (e.g. 204)
     * @param array<array<string>> $headers as returned by PSR-7 getHeaders(), whose stub guarantees neither string keys nor list values
     */
    public function __construct(
        public int $status,
        public array $data,
        public array $headers = [],
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp((string) $header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
