<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The inbound HTTP request exactly as SignatureValidator needs to see it —
 * framework-free, so any runtime (PSR-7, Laravel, Symfony, a queued replay)
 * can build one.
 *
 * $rawBody must be the EXACT bytes received. Never rebuild it from a decoded
 * payload: re-encoding can reorder keys or change whitespace, and the content
 * hash is computed over bytes, not meaning — a byte-perfect payload would
 * mysteriously fail, or worse, a tampered one could be re-encoded into a
 * false pass.
 *
 * Header names are matched case-insensitively (HTTP semantics; proxies and
 * frameworks disagree on canonical casing).
 */
final readonly class WebhookRequest
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param string $pathAndQuery as sent on the wire, e.g. "/hooks/vipps?x=1" — part of the signed string
     * @param string $host the Host header value the signer saw, port included when non-default
     * @param array<string, string> $headers at minimum x-ms-date, x-ms-content-sha256 and Authorization
     */
    public function __construct(
        public string $method,
        public string $pathAndQuery,
        public string $host,
        public string $rawBody,
        array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->headers = $normalized;
    }

    /**
     * Convenience for PSR-7 receivers. Pulls only what validation reads: the
     * request target (path + query as sent), the Host header, the raw body
     * bytes and the three signature headers — nothing else from the request
     * is ever part of the signed string.
     */
    public static function fromPsr7(ServerRequestInterface $request): self
    {
        $headers = [];
        foreach (['x-ms-date', 'x-ms-content-sha256', 'Authorization'] as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->getHeaderLine($name);
            }
        }

        return new self(
            $request->getMethod(),
            $request->getRequestTarget(),
            $request->getHeaderLine('Host'),
            (string) $request->getBody(),
            $headers,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
