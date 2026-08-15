<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Epayment\CreatePayment;
use Nesthus\Vipps\Epayment\UserFlow;
use Nesthus\Vipps\Exceptions\VippsConfigException;

it('builds the minimal payload with WALLET and the default WEB_REDIRECT flow', function () {
    $payment = new CreatePayment(
        amount: Amount::fromMajor(49),
        reference: 'order-2026-000123',
        returnUrl: 'https://shop.example/return',
    );

    expect($payment->toPayload())->toBe([
        'amount' => ['currency' => 'NOK', 'value' => 4900],
        'paymentMethod' => ['type' => 'WALLET'],
        'reference' => 'order-2026-000123',
        'returnUrl' => 'https://shop.example/return',
        'userFlow' => 'WEB_REDIRECT',
    ]);
});

it('includes customer and paymentDescription only when provided', function () {
    $payment = new CreatePayment(
        amount: Amount::fromMinor(1000, 'EUR'),
        reference: 'order-2026-000124',
        returnUrl: 'https://shop.example/return',
        userFlow: UserFlow::PushMessage,
        customerPhoneNumber: '4712345678',
        paymentDescription: 'One pair of socks',
    );

    expect($payment->toPayload())
        ->toHaveKey('customer', ['phoneNumber' => '4712345678'])
        ->toHaveKey('paymentDescription', 'One pair of socks')
        ->toHaveKey('userFlow', 'PUSH_MESSAGE')
        ->toHaveKey('amount', ['currency' => 'EUR', 'value' => 1000]);
});

it('serialises every user flow to its wire value', function (UserFlow $flow, string $wire) {
    $payment = new CreatePayment(
        amount: Amount::fromMinor(100),
        reference: 'order-2026-000125',
        returnUrl: 'https://shop.example/return',
        userFlow: $flow,
    );

    expect($payment->toPayload()['userFlow'])->toBe($wire);
})->with([
    'web redirect' => [UserFlow::WebRedirect, 'WEB_REDIRECT'],
    'native' => [UserFlow::Native, 'NATIVE'],
    'push message' => [UserFlow::PushMessage, 'PUSH_MESSAGE'],
    'qr' => [UserFlow::Qr, 'QR'],
]);

it('rejects an out-of-spec reference', function (string $reference) {
    new CreatePayment(
        amount: Amount::fromMinor(100),
        reference: $reference,
        returnUrl: 'https://shop.example/return',
    );
})->with([
    '7 chars (too short)' => [str_repeat('a', 7)],
    '65 chars (too long)' => [str_repeat('a', 65)],
    'space' => ['order 12345'],
    'underscore' => ['order_12345'],
    'non-ascii' => ['ordre-blåbær-1'],
    'empty' => [''],
])->throws(VippsConfigException::class);

it('rejects a customer phone number that is not a bare MSISDN', function (string $phone) {
    // Same wire concept, same rule as Recurring's NewAgreement.phoneNumber:
    // country code included, no plus sign.
    new CreatePayment(
        amount: Amount::fromMinor(100),
        reference: 'order-2026-000126',
        returnUrl: 'https://shop.example/return',
        customerPhoneNumber: $phone,
    );
})->with([
    'plus prefix' => ['+4712345678'],
    'spaces' => ['47 12 34 56 78'],
    '7 digits (too short)' => ['1234567'],
    '16 digits (too long)' => [str_repeat('9', 16)],
    'empty' => [''],
])->throws(VippsConfigException::class);

it('accepts references at the 8 and 64 character boundaries', function (string $reference) {
    $payment = new CreatePayment(
        amount: Amount::fromMinor(100),
        reference: $reference,
        returnUrl: 'https://shop.example/return',
    );

    expect($payment->reference)->toBe($reference);
})->with([
    '8 chars' => [str_repeat('a', 8)],
    '64 chars' => [str_repeat('b', 64)],
    'all legal character classes' => ['Order-AZ-az-09'],
]);
