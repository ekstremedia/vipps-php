<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Http;

use JsonException;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\VippsConfig;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The single place an HTTP request to Vipps is built and its response
 * interpreted. Everything above this (Recurring, ePayment, Login, Webhooks)
 * deals in arrays and value objects only.
 *
 * Owns the headers every call needs — subscription key, merchant serial
 * number, the Vipps-System-* quartet — but NOT authentication: bearer tokens
 * are AuthenticatedTransport's job, because the token endpoint itself must be
 * callable without one (chicken and egg otherwise).
 *
 * Deliberately PSR-18 all the way down: the SDK never picks an HTTP client,
 * so the integrator's timeout policy applies. Configure timeouts on the
 * client you inject — most PSR-18 clients (Guzzle included) wait FOREVER by
 * default, and a payment API call with no deadline can wedge a worker.
 */
final readonly class ApiTransport implements Transport
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private VippsConfig $config,
    ) {}

    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): ApiResponse {
        $request = $this->requestFactory->createRequest($method, $this->config->baseUrl() . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Ocp-Apim-Subscription-Key', $this->config->subscriptionKey())
            ->withHeader('Merchant-Serial-Number', $this->config->merchantSerialNumber);

        foreach ($this->config->system->headers() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->encode($json)));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw VippsApiException::fromTransport($method, $path, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw VippsApiException::fromResponse($method, $path, $status, $body);
        }

        return new ApiResponse($status, $this->decode($body), $response->getHeaders());
    }

    /**
     * Same pipeline as request(), but with an application/x-www-form-urlencoded
     * body. Exists for exactly one consumer: the OIDC token exchange in
     * LoginApi, which is form-encoded per the OAuth2 spec while every other
     * Vipps endpoint speaks JSON.
     *
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    public function requestForm(string $method, string $path, array $form, array $headers = []): ApiResponse
    {
        $request = $this->requestFactory->createRequest($method, $this->config->baseUrl() . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Ocp-Apim-Subscription-Key', $this->config->subscriptionKey())
            ->withHeader('Merchant-Serial-Number', $this->config->merchantSerialNumber)
            // The explicit '&' matters: without it http_build_query() honors
            // the arg_separator.output ini setting, and a host tuned for HTML
            // output (';') would silently corrupt the OAuth form body.
            ->withBody($this->streamFactory->createStream(http_build_query($form, '', '&')));

        foreach ($this->config->system->headers() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw VippsApiException::fromTransport($method, $path, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw VippsApiException::fromResponse($method, $path, $status, $body);
        }

        return new ApiResponse($status, $this->decode($body), $response->getHeaders());
    }

    /**
     * @param array<string, mixed> $json
     */
    private function encode(array $json): string
    {
        try {
            return json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            // Caller-supplied data that PHP cannot represent as JSON (most
            // commonly invalid UTF-8 in a description) is an integrator bug,
            // not a Vipps error — so it maps to VippsConfigException, keeping
            // the marker-interface promise instead of leaking a bare
            // JsonException. The payload itself deliberately stays out of the
            // message: it can carry PII or credentials.
            throw new VippsConfigException(
                "Request payload cannot be encoded as JSON ({$e->getMessage()}).",
                previous: $e,
            );
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        // A 2xx with a non-JSON body would be a Vipps contract violation;
        // surface it as an empty payload rather than a decode fatal.
        if (! json_validate($body)) {
            return [];
        }

        return (array) json_decode($body, true);
    }
}
