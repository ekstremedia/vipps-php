<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Epayment\CreatePayment;
use Nesthus\Vipps\Epayment\EpaymentApi;
use Nesthus\Vipps\Epayment\PaymentState;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;

beforeEach(function () {
    $this->http = new FakeHttpClient();
    $factory = new HttpFactory();

    $this->api = new EpaymentApi(new ApiTransport(
        $this->http,
        $factory,
        $factory,
        new VippsConfig('client-id', 'client-secret', 'subscription-key', '123456'),
    ));

    $this->draft = new CreatePayment(
        amount: Amount::fromMajor(49),
        reference: 'order-2026-000123',
        returnUrl: 'https://shop.example/return',
    );
});

it('creates a payment: POST /epayment/v1/payments with the payload and the caller\'s Idempotency-Key', function () {
    $this->http->queueJson(201, [
        'reference' => 'order-2026-000123',
        'redirectUrl' => 'https://landing.vipps.no?token=abc',
    ]);

    $created = $this->api->createPayment($this->draft, 'idem-create-1');

    $request = $this->http->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/epayment/v1/payments')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-create-1')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $request->getBody(), true))->toBe($this->draft->toPayload())
        ->and($created->reference)->toBe('order-2026-000123')
        ->and($created->redirectUrl)->toBe('https://landing.vipps.no?token=abc');
});

it('maps a created payment without a redirectUrl (push flow) to null', function () {
    $this->http->queueJson(201, ['reference' => 'order-2026-000123']);

    $created = $this->api->createPayment($this->draft, 'idem-create-2');

    expect($created->redirectUrl)->toBeNull();
});

it('refuses a created payment without a reference — the payment could never be addressed again', function (array $body) {
    $this->http->queueJson(201, $body);

    expect(fn() => $this->api->createPayment($this->draft, 'idem-create-3'))
        ->toThrow(VippsMalformedResponseException::class, 'reference');
})->with([
    'reference absent' => [['redirectUrl' => 'https://landing.vipps.no?token=abc']],
    'reference not a string' => [['reference' => 42]],
    'reference empty' => [['reference' => '']],
]);

it('gets a payment: GET /payments/{reference} without an Idempotency-Key', function () {
    $this->http->queueJson(200, [
        'reference' => 'order-2026-000123',
        'state' => 'AUTHORIZED',
        'aggregate' => ['authorizedAmount' => ['currency' => 'NOK', 'value' => 4900]],
    ]);

    $payment = $this->api->getPayment('order-2026-000123');

    $request = $this->http->lastRequest();
    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/epayment/v1/payments/order-2026-000123')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($payment->state)->toBe(PaymentState::Authorized)
        ->and($payment->authorizedAmount?->minorUnits)->toBe(4900);
});

it('percent-encodes a hostile reference in the path', function () {
    $this->http->queueJson(200, ['reference' => 'x', 'state' => 'CREATED']);

    $this->api->getPayment('../other endpoint');

    expect((string) $this->http->lastRequest()->getUri())
        ->toBe('https://apitest.vipps.no/epayment/v1/payments/..%2Fother%20endpoint');
});

it('lists the event trail: GET /payments/{reference}/events, in order', function () {
    $this->http->queueJson(200, [
        [
            'name' => 'CREATED',
            'amount' => ['currency' => 'NOK', 'value' => 4900],
            'timestamp' => '2026-08-14T11:39:00Z',
            'idempotencyKey' => 'idem-create-1',
            'success' => true,
        ],
        [
            'name' => 'AUTHORIZED',
            'amount' => ['currency' => 'NOK', 'value' => 4900],
            'timestamp' => '2026-08-14T11:40:19Z',
            'idempotencyKey' => null,
            'success' => true,
        ],
    ]);

    $events = $this->api->getEvents('order-2026-000123');

    expect((string) $this->http->lastRequest()->getUri())
        ->toBe('https://apitest.vipps.no/epayment/v1/payments/order-2026-000123/events')
        ->and($events)->toHaveCount(2)
        ->and($events[0]->name)->toBe('CREATED')
        ->and($events[0]->idempotencyKey)->toBe('idem-create-1')
        ->and($events[1]->name)->toBe('AUTHORIZED')
        ->and($events[1]->idempotencyKey)->toBeNull();
});

it('returns an empty trail for an empty response body', function () {
    $this->http->queueRaw(200);

    expect($this->api->getEvents('order-2026-000123'))->toBe([]);
});

it('captures: POST /payments/{reference}/capture with modificationAmount and the caller\'s Idempotency-Key', function () {
    // The adjustment response IS the verification Vipps tells merchants to
    // do before shipping — so it must come back typed, aggregates included.
    $this->http->queueJson(200, [
        'reference' => 'order-2026-000123',
        'state' => 'AUTHORIZED',
        'aggregate' => [
            'authorizedAmount' => ['currency' => 'NOK', 'value' => 4900],
            'capturedAmount' => ['currency' => 'NOK', 'value' => 2000],
        ],
    ]);

    $payment = $this->api->capture('order-2026-000123', Amount::fromMajor(20), 'idem-capture-1');

    $request = $this->http->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/epayment/v1/payments/order-2026-000123/capture')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-capture-1')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'modificationAmount' => ['currency' => 'NOK', 'value' => 2000],
        ])
        ->and($payment->state)->toBe(PaymentState::Authorized)
        ->and($payment->capturedAmount?->minorUnits)->toBe(2000)
        ->and($payment->authorizedAmount?->minorUnits)->toBe(4900);
});

it('cancels: POST /payments/{reference}/cancel with no body but the caller\'s Idempotency-Key', function () {
    $this->http->queueJson(200, [
        'reference' => 'order-2026-000123',
        'state' => 'TERMINATED',
        'aggregate' => ['cancelledAmount' => ['currency' => 'NOK', 'value' => 4900]],
    ]);

    $payment = $this->api->cancel('order-2026-000123', 'idem-cancel-1');

    $request = $this->http->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/epayment/v1/payments/order-2026-000123/cancel')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-cancel-1')
        ->and((string) $request->getBody())->toBe('')
        ->and($payment->state)->toBe(PaymentState::Terminated)
        ->and($payment->cancelledAmount?->minorUnits)->toBe(4900);
});

it('refunds: POST /payments/{reference}/refund with modificationAmount and the caller\'s Idempotency-Key', function () {
    $this->http->queueJson(200, [
        'reference' => 'order-2026-000123',
        'state' => 'AUTHORIZED',
        'aggregate' => ['refundedAmount' => ['currency' => 'NOK', 'value' => 500]],
    ]);

    $payment = $this->api->refund('order-2026-000123', Amount::fromMinor(500), 'idem-refund-1');

    $request = $this->http->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/epayment/v1/payments/order-2026-000123/refund')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-refund-1')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'modificationAmount' => ['currency' => 'NOK', 'value' => 500],
        ])
        ->and($payment->refundedAmount?->minorUnits)->toBe(500);
});

it('surfaces the 409 for a reused reference as a VippsApiException', function () {
    $this->http->queueJson(409, ['title' => 'Duplicate reference', 'traceId' => 'trace-409']);

    try {
        $this->api->createPayment($this->draft, 'idem-create-1');
        $this->fail('Expected a VippsApiException.');
    } catch (VippsApiException $e) {
        expect($e->status)->toBe(409)
            ->and($e->traceId)->toBe('trace-409')
            ->and($e->getMessage())->toContain('HTTP 409')
            ->and($e->getMessage())->toContain('Duplicate reference');
    }
});
