<?php

declare(strict_types=1);

use Nesthus\Vipps\Epayment\PaymentEvent;

it('maps a merchant-initiated capture event', function () {
    $event = PaymentEvent::fromArray([
        'reference' => 'order-2026-000123',
        'name' => 'CAPTURED',
        'amount' => ['currency' => 'NOK', 'value' => 2000],
        'timestamp' => '2026-08-14T11:40:19.161Z',
        'idempotencyKey' => 'capture-shipment-1',
        'success' => true,
    ]);

    expect($event->name)->toBe('CAPTURED')
        ->and($event->amount?->minorUnits)->toBe(2000)
        ->and($event->amount?->currency)->toBe('NOK')
        ->and($event->timestamp?->format(DateTimeInterface::ATOM))->toBe('2026-08-14T11:40:19+00:00')
        ->and($event->success)->toBeTrue()
        ->and($event->idempotencyKey)->toBe('capture-shipment-1');
});

it('treats a Vipps-initiated event with a null idempotencyKey as having none', function () {
    $event = PaymentEvent::fromArray([
        'name' => 'EXPIRED',
        'amount' => ['currency' => 'NOK', 'value' => 4900],
        'timestamp' => '2026-08-14T12:00:00Z',
        'idempotencyKey' => null,
        'success' => true,
    ]);

    expect($event->idempotencyKey)->toBeNull();
});

it('keeps an event name this SDK has never seen instead of crashing', function () {
    $event = PaymentEvent::fromArray([
        'name' => 'SOME_FUTURE_EVENT',
        'success' => false,
    ]);

    expect($event->name)->toBe('SOME_FUTURE_EVENT')
        ->and($event->success)->toBeFalse()
        ->and($event->amount)->toBeNull()
        ->and($event->timestamp)->toBeNull();
});

it('maps an unparseable timestamp to null instead of throwing', function () {
    $event = PaymentEvent::fromArray([
        'name' => 'AUTHORIZED',
        'timestamp' => 'not a timestamp',
        'success' => true,
    ]);

    expect($event->timestamp)->toBeNull();
});
