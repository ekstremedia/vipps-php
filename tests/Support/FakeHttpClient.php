<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Recording PSR-18 fake for the whole test suite: queue responses up front,
 * assert on the requests afterwards. No HTTP, no mocking framework — the
 * transport is exercised for real down to the PSR-7 boundary.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $json
     */
    public function queueJson(int $status, array $json = [], array $headers = []): self
    {
        $this->queue[] = new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($json, JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    public function queueRaw(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new RuntimeException('FakeHttpClient queue is empty — queue a response before the call under test.');
        }

        return $response;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new RuntimeException('No request was sent.');
        }

        return $last;
    }
}
