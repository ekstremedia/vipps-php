<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Recurring\AgreementPatch;
use Nesthus\Vipps\Recurring\ChargeTransactionType;
use Nesthus\Vipps\Recurring\Interval;
use Nesthus\Vipps\Recurring\NewAgreement;
use Nesthus\Vipps\Recurring\NewCharge;
use Nesthus\Vipps\Recurring\Pricing;
use Nesthus\Vipps\Recurring\PricingType;

it('rejects an interval count below one', function () {
    expect(fn() => Interval::months(0))->toThrow(VippsConfigException::class, 'Interval count must be at least 1.');
});

it('builds intervals through the named constructors', function () {
    expect(Interval::days(10)->toPayload())->toBe(['unit' => 'DAY', 'count' => 10])
        ->and(Interval::weeks(2)->toPayload())->toBe(['unit' => 'WEEK', 'count' => 2])
        ->and(Interval::months(3)->toPayload())->toBe(['unit' => 'MONTH', 'count' => 3])
        ->and(Interval::years(1)->toPayload())->toBe(['unit' => 'YEAR', 'count' => 1]);
});

it('rejects retryDays outside 0-14', function (int $retryDays) {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        due: '2026-10-01',
        retryDays: $retryDays,
    ))->toThrow(VippsConfigException::class, 'retryDays must be between 0 and 14.');
})->with([-1, 15]);

it('rejects a due date string that is not an ISO date', function (string $due) {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        due: $due,
        retryDays: 2,
    ))->toThrow(VippsConfigException::class);
})->with(['01.10.2026', '2026-10-01T00:00:00Z', 'tomorrow']);

it('rejects a phone number carrying a plus sign', function () {
    expect(fn() => new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(9900)),
        interval: Interval::months(1),
        productName: 'Plan',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
        phoneNumber: '+4791234567',
    ))->toThrow(VippsConfigException::class);
});

it('rejects an empty AgreementPatch', function () {
    expect(fn() => new AgreementPatch())
        ->toThrow(VippsConfigException::class, 'AgreementPatch is empty — set at least one field to change.');
});

it('falls back to LEGACY for an unknown pricing type in a response', function () {
    $pricing = Pricing::fromArray(['type' => 'BRAND_NEW', 'amount' => 100, 'currency' => 'EUR']);

    expect($pricing->type)->toBe(PricingType::Legacy)
        ->and($pricing->amount?->minorUnits)->toBe(100)
        ->and($pricing->amount?->currency)->toBe('EUR');
});
