<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\SystemInfo;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

beforeEach(function () {
    $this->client = new FakeHttpClient();
    $factory = new HttpFactory();
    $this->transport = new ApiTransport(
        $this->client,
        $factory,
        $factory,
        new VippsConfig(
            clientId: 'client-id',
            clientSecret: 'client-secret',
            subscriptionKey: 'sub-key',
            merchantSerialNumber: '123456',
            system: new SystemInfo('My Webshop', '2.1.0'),
            baseUrlOverride: 'https://vipps.example',
        ),
    );
});

describe('request building', function () {
    it('carries the credential and system headers on every call', function () {
        $this->client->queueJson(200);

        $this->transport->request('GET', '/epayment/v1/payments/abc');

        $request = $this->client->lastRequest();
        expect($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('https://vipps.example/epayment/v1/payments/abc')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Ocp-Apim-Subscription-Key'))->toBe('sub-key')
            ->and($request->getHeaderLine('Merchant-Serial-Number'))->toBe('123456')
            ->and($request->getHeaderLine('Vipps-System-Name'))->toBe('My Webshop')
            ->and($request->getHeaderLine('Vipps-System-Version'))->toBe('2.1.0')
            ->and($request->getHeaderLine('Vipps-System-Plugin-Name'))->toBe('nesthus/vipps-php')
            ->and($request->getHeaderLine('Vipps-System-Plugin-Version'))->not->toBe('');
    });

    it('omits Idempotency-Key unless the caller supplies one', function () {
        $this->client->queueJson(200);

        $this->transport->request('GET', '/x');

        expect($this->client->lastRequest()->hasHeader('Idempotency-Key'))->toBeFalse();
    });

    it('sends the caller-supplied Idempotency-Key verbatim', function () {
        $this->client->queueJson(200);

        $this->transport->request('POST', '/x', [], idempotencyKey: 'order-1234');

        expect($this->client->lastRequest()->getHeaderLine('Idempotency-Key'))->toBe('order-1234');
    });

    it('encodes the JSON body without escaping unicode or slashes', function () {
        $this->client->queueJson(200);

        $this->transport->request('POST', '/x', [
            'description' => 'Blåbærsyltetøy',
            'url' => 'https://shop.example/return',
        ]);

        $request = $this->client->lastRequest();
        expect($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and((string) $request->getBody())->toBe('{"description":"Blåbærsyltetøy","url":"https://shop.example/return"}');
    });

    it('maps an unencodable payload to VippsConfigException without leaking it', function () {
        try {
            // Invalid UTF-8 — the classic way a caller-supplied description
            // makes json_encode throw.
            $this->transport->request('POST', '/x', ['description' => "\xB1\x31"]);
            $this->fail('Expected VippsConfigException was not thrown.');
        } catch (VippsConfigException $e) {
            expect($e->getMessage())->toContain('JSON')
                ->and($e->getMessage())->not->toContain("\xB1")
                ->and($e->getPrevious())->toBeInstanceOf(JsonException::class);
        }

        // Thrown before anything went on the wire.
        expect($this->client->requests)->toBe([]);
    });

    it('sends no body and no Content-Type when there is no JSON payload', function () {
        $this->client->queueJson(200);

        $this->transport->request('GET', '/x');

        $request = $this->client->lastRequest();
        expect($request->hasHeader('Content-Type'))->toBeFalse()
            ->and((string) $request->getBody())->toBe('');
    });

    it('lets extra per-call headers win over the defaults', function () {
        $this->client->queueJson(200);

        $this->transport->request('GET', '/x', headers: [
            'Accept' => 'text/plain',
            'X-Custom' => 'yes',
        ]);

        $request = $this->client->lastRequest();
        expect($request->getHeaderLine('Accept'))->toBe('text/plain')
            ->and($request->getHeaderLine('X-Custom'))->toBe('yes');
    });
});

describe('response handling', function () {
    it('returns status, decoded data and headers on 2xx', function () {
        $this->client->queueJson(201, ['reference' => 'ref-1'], ['X-Trace' => 'abc']);

        $response = $this->transport->request('POST', '/x', []);

        expect($response->status)->toBe(201)
            ->and($response->data)->toBe(['reference' => 'ref-1'])
            ->and($response->header('x-trace'))->toBe('abc');
    });

    it('treats a 204 empty body as empty data', function () {
        $this->client->queueRaw(204);

        expect($this->transport->request('DELETE', '/x')->data)->toBe([]);
    });

    it('treats a 2xx non-JSON body as empty data instead of a decode fatal', function () {
        $this->client->queueRaw(200, 'OK, but not JSON');

        expect($this->transport->request('GET', '/x')->data)->toBe([]);
    });
});

describe('error mapping', function () {
    it('maps a 4xx problem+json body to status, details and traceId', function () {
        $this->client->queueRaw(400, json_encode([
            'type' => 'https://vipps.example/errors/validation',
            'title' => 'Bad Request',
            'detail' => 'amount must be positive',
            'traceId' => 'trace-123',
        ], JSON_THROW_ON_ERROR));

        try {
            $this->transport->request('POST', '/x', []);
            $this->fail('Expected VippsApiException was not thrown.');
        } catch (VippsApiException $e) {
            expect($e->status)->toBe(400)
                ->and($e->details['detail'])->toBe('amount must be positive')
                ->and($e->traceId)->toBe('trace-123')
                ->and($e->getMessage())->toContain('POST /x')
                ->and($e->getMessage())->toContain('Bad Request');
        }
    });

    it('keeps a non-JSON 5xx body out of the exception entirely', function () {
        $this->client->queueRaw(502, '<html>gateway exploded: secret backend hostname</html>');

        try {
            $this->transport->request('GET', '/x');
            $this->fail('Expected VippsApiException was not thrown.');
        } catch (VippsApiException $e) {
            expect($e->status)->toBe(502)
                ->and($e->details)->toBe([])
                ->and($e->getMessage())->toBe('Vipps API GET /x failed with HTTP 502');
        }
    });

    it('wraps a PSR-18 transport failure as status 0 with the cause chained', function () {
        $cause = new class ('connection refused') extends RuntimeException implements ClientExceptionInterface {};
        $client = new class ($cause) implements ClientInterface {
            public function __construct(private readonly ClientExceptionInterface $exception) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
        $factory = new HttpFactory();
        $transport = new ApiTransport($client, $factory, $factory, new VippsConfig('id', 'secret', 'key', 'msn'));

        try {
            $transport->request('GET', '/x');
            $this->fail('Expected VippsApiException was not thrown.');
        } catch (VippsApiException $e) {
            expect($e->status)->toBe(0)
                ->and($e->getPrevious())->toBe($cause)
                ->and($e->getMessage())->toContain('before a response was received');
        }
    });
});

describe('requestForm', function () {
    it('sends a form-encoded body with the credential headers intact', function () {
        $this->client->queueJson(200, ['access_token' => 't']);

        $this->transport->requestForm('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'abc def',
        ]);

        $request = $this->client->lastRequest();
        expect($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
            ->and((string) $request->getBody())->toBe('grant_type=authorization_code&code=abc+def')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Ocp-Apim-Subscription-Key'))->toBe('sub-key')
            ->and($request->getHeaderLine('Merchant-Serial-Number'))->toBe('123456')
            ->and($request->getHeaderLine('Vipps-System-Name'))->toBe('My Webshop');
    });

    it('joins form pairs with a literal &, regardless of arg_separator.output', function () {
        // http_build_query() with no separator argument honors the
        // arg_separator.output ini setting — a host tuned for HTML output
        // (';') would corrupt the OAuth body. Prove the separator is pinned.
        $previous = ini_set('arg_separator.output', ';');

        try {
            $this->client->queueJson(200);
            $this->transport->requestForm('POST', '/oauth/token', ['a' => '1', 'b' => '2']);
        } finally {
            if ($previous !== false) {
                ini_set('arg_separator.output', $previous);
            }
        }

        expect((string) $this->client->lastRequest()->getBody())->toBe('a=1&b=2');
    });

    it('lets extra per-call headers win, and maps errors the same way', function () {
        $this->client->queueRaw(401, json_encode(['error' => 'invalid_client'], JSON_THROW_ON_ERROR));

        try {
            $this->transport->requestForm('POST', '/oauth/token', ['a' => 'b'], ['Authorization' => 'Basic xyz']);
            $this->fail('Expected VippsApiException was not thrown.');
        } catch (VippsApiException $e) {
            expect($e->status)->toBe(401)
                ->and($e->getMessage())->toContain('invalid_client');
        }

        expect($this->client->lastRequest()->getHeaderLine('Authorization'))->toBe('Basic xyz');
    });
});
