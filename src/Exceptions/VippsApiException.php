<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A call to the Vipps API failed — either the transport itself (network,
 * DNS, TLS; status 0) or Vipps answered with an error status.
 *
 * The message deliberately contains only method, path, status and Vipps'
 * own title/detail — never request headers or bodies, because those carry
 * credentials (client_secret, subscription key, bearer token) and exception
 * messages have a habit of ending up in logs.
 */
final class VippsApiException extends RuntimeException implements VippsException
{
    /**
     * @param array<string, mixed> $details decoded error body (Vipps problem+json when available)
     */
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly array $details = [],
        public readonly ?string $traceId = null,
        ?ClientExceptionInterface $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(string $method, string $path, int $status, string $rawBody): self
    {
        /** @var array<string, mixed> $details */
        $details = json_validate($rawBody) ? (array) json_decode($rawBody, true) : [];

        $title = self::firstString($details, ['title', 'detail', 'message', 'error']);
        $traceId = self::firstString($details, ['traceId', 'trace_id', 'contextId']);

        $summary = $title !== null ? ": {$title}" : '';

        return new self(
            "Vipps API {$method} {$path} failed with HTTP {$status}{$summary}",
            status: $status,
            details: $details,
            traceId: $traceId,
        );
    }

    public static function fromTransport(string $method, string $path, ClientExceptionInterface $previous): self
    {
        return new self(
            "Vipps API {$method} {$path} failed before a response was received: {$previous->getMessage()}",
            status: 0,
            previous: $previous,
        );
    }

    /**
     * @param array<string, mixed> $details
     * @param list<string> $keys
     */
    private static function firstString(array $details, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($details[$key]) && is_string($details[$key]) && $details[$key] !== '') {
                return $details[$key];
            }
        }

        return null;
    }
}
