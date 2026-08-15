<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;
use Nesthus\Vipps\Webhooks\Webhook;
use Nesthus\Vipps\Webhooks\WebhooksApi;

function webhooksApiOver(FakeHttpClient $http): WebhooksApi
{
    $factory = new HttpFactory();

    return new WebhooksApi(new ApiTransport(
        $http,
        $factory,
        $factory,
        new VippsConfig('client-id', 'client-secret', 'subscription-key', '123456'),
    ));
}

it('registers a webhook with the caller-supplied idempotency key and returns the one-time secret', function (): void {
    $http = new FakeHttpClient();
    $http->queueJson(201, ['id' => 'wh-1', 'secret' => 'whsec-abc']);

    $registered = webhooksApiOver($http)->register(
        'https://merchant.example.no/hooks/vipps',
        ['epayments.payment.captured.v1', 'epayments.payment.cancelled.v1'],
        idempotencyKey: 'idem-123',
    );

    expect($registered->id)->toBe('wh-1')
        ->and($registered->secret())->toBe('whsec-abc');

    $request = $http->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/webhooks/v1/webhooks')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-123')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'url' => 'https://merchant.example.no/hooks/vipps',
            'events' => ['epayments.payment.captured.v1', 'epayments.payment.cancelled.v1'],
        ]);
});

it('tolerates unknown extra keys in the register response', function (): void {
    $http = new FakeHttpClient();
    $http->queueJson(201, ['id' => 'wh-1', 'secret' => 'whsec-abc', 'someFutureField' => ['x' => 1]]);

    $registered = webhooksApiOver($http)->register('https://merchant.example.no/h', ['e.v1'], 'idem-1');

    expect($registered->id)->toBe('wh-1')
        ->and($registered->secret())->toBe('whsec-abc');
});

it('lists registered webhooks', function (): void {
    $http = new FakeHttpClient();
    $http->queueJson(200, ['webhooks' => [
        ['id' => 'wh-1', 'url' => 'https://a.example.no/h', 'events' => ['e.v1'], 'extra' => true],
        ['id' => 'wh-2', 'url' => 'https://b.example.no/h', 'events' => ['e.v1', 'f.v1']],
    ]]);

    $webhooks = webhooksApiOver($http)->all();

    expect($webhooks)->toHaveCount(2)
        ->and($webhooks[0])->toBeInstanceOf(Webhook::class)
        ->and($webhooks[0]->id)->toBe('wh-1')
        ->and($webhooks[0]->url)->toBe('https://a.example.no/h')
        ->and($webhooks[0]->events)->toBe(['e.v1'])
        ->and($webhooks[1]->events)->toBe(['e.v1', 'f.v1']);

    $request = $http->lastRequest();
    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/webhooks/v1/webhooks');
});

it('returns an empty list when the webhooks key is missing or not a list', function (): void {
    $http = new FakeHttpClient();
    $http->queueJson(200, []);

    expect(webhooksApiOver($http)->all())->toBe([]);

    $http->queueJson(200, ['webhooks' => 'unexpected']);

    expect(webhooksApiOver($http)->all())->toBe([]);
});

it('maps a listed webhook with missing optionals to safe defaults', function (): void {
    $http = new FakeHttpClient();
    $http->queueJson(200, ['webhooks' => [
        ['id' => 'wh-1'],
        'not-an-object-row',
    ]]);

    $webhooks = webhooksApiOver($http)->all();

    expect($webhooks)->toHaveCount(1)
        ->and($webhooks[0]->id)->toBe('wh-1')
        ->and($webhooks[0]->url)->toBe('')
        ->and($webhooks[0]->events)->toBe([]);
});

it('deletes a webhook by id with the caller-supplied idempotency key', function (): void {
    $http = new FakeHttpClient();
    $http->queueRaw(204);

    webhooksApiOver($http)->delete('wh-1', idempotencyKey: 'idem-del-1');

    $request = $http->lastRequest();
    expect($request->getMethod())->toBe('DELETE')
        ->and((string) $request->getUri())->toBe('https://apitest.vipps.no/webhooks/v1/webhooks/wh-1')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-del-1')
        ->and((string) $request->getBody())->toBe('');
});
