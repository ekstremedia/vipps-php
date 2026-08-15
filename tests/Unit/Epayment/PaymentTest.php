<?php

declare(strict_types=1);

use Nesthus\Vipps\Epayment\Payment;
use Nesthus\Vipps\Epayment\PaymentState;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

it('maps a fully populated payment, ignoring unknown extra keys', function () {
    $payment = Payment::fromArray([
        'reference' => 'order-2026-000123',
        'state' => 'AUTHORIZED',
        'aggregate' => [
            'authorizedAmount' => ['currency' => 'NOK', 'value' => 4900],
            'capturedAmount' => ['currency' => 'NOK', 'value' => 2000],
            'refundedAmount' => ['currency' => 'NOK', 'value' => 500],
            'cancelledAmount' => ['currency' => 'NOK', 'value' => 0],
        ],
        'paymentMethod' => ['type' => 'WALLET'],
        'profile' => ['sub' => 'c06c4afe-d9e1-4c5d-939a-177d752a0944'],
        'pspReference' => 'a-key-this-sdk-has-never-heard-of',
    ]);

    expect($payment->reference)->toBe('order-2026-000123')
        ->and($payment->state)->toBe(PaymentState::Authorized)
        ->and($payment->authorizedAmount?->minorUnits)->toBe(4900)
        ->and($payment->authorizedAmount?->currency)->toBe('NOK')
        ->and($payment->capturedAmount?->minorUnits)->toBe(2000)
        ->and($payment->refundedAmount?->minorUnits)->toBe(500)
        ->and($payment->cancelledAmount?->minorUnits)->toBe(0)
        ->and($payment->paymentMethod?->type)->toBe('WALLET')
        ->and($payment->paymentMethod?->cardBin)->toBeNull()
        ->and($payment->profile?->sub)->toBe('c06c4afe-d9e1-4c5d-939a-177d752a0944');
});

it('maps every payment state', function (string $wire, PaymentState $expected) {
    $payment = Payment::fromArray(['reference' => 'order-2026-000123', 'state' => $wire]);

    expect($payment->state)->toBe($expected);
})->with([
    'created' => ['CREATED', PaymentState::Created],
    'aborted' => ['ABORTED', PaymentState::Aborted],
    'expired' => ['EXPIRED', PaymentState::Expired],
    'authorized' => ['AUTHORIZED', PaymentState::Authorized],
    'terminated' => ['TERMINATED', PaymentState::Terminated],
]);

it('leaves aggregates Vipps has not touched as null', function () {
    $payment = Payment::fromArray(['reference' => 'order-2026-000123', 'state' => 'CREATED']);

    expect($payment->authorizedAmount)->toBeNull()
        ->and($payment->capturedAmount)->toBeNull()
        ->and($payment->refundedAmount)->toBeNull()
        ->and($payment->cancelledAmount)->toBeNull()
        ->and($payment->paymentMethod)->toBeNull()
        ->and($payment->profile)->toBeNull();
});

it('maps a malformed aggregate amount to null instead of throwing', function () {
    $payment = Payment::fromArray([
        'reference' => 'order-2026-000123',
        'state' => 'AUTHORIZED',
        'aggregate' => [
            'authorizedAmount' => ['currency' => 'NOK', 'value' => '4900'],
            'capturedAmount' => 'not-an-object',
        ],
    ]);

    expect($payment->authorizedAmount)->toBeNull()
        ->and($payment->capturedAmount)->toBeNull();
});

it('refuses a state this SDK does not know rather than guessing', function () {
    expect(fn() => Payment::fromArray(['reference' => 'order-2026-000123', 'state' => 'SOMETHING_NEW']))
        ->toThrow(VippsMalformedResponseException::class, 'SOMETHING_NEW');
});

it('refuses a payment without a state rather than inventing one', function (array $body) {
    expect(fn() => Payment::fromArray($body))
        ->toThrow(VippsMalformedResponseException::class, 'state');
})->with([
    'state absent' => [['reference' => 'order-2026-000123']],
    'state not a string' => [['reference' => 'order-2026-000123', 'state' => 42]],
    'state empty' => [['reference' => 'order-2026-000123', 'state' => '']],
]);
