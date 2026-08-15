<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Recurring\AgreementPatch;
use Nesthus\Vipps\Recurring\ChargeTransactionType;
use Nesthus\Vipps\Recurring\ChargeType;
use Nesthus\Vipps\Recurring\InitialCharge;
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

it('rejects a RECURRING charge missing due or retryDays', function (DateTimeInterface|string|null $due, ?int $retryDays, ?ChargeType $type) {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        due: $due,
        retryDays: $retryDays,
        type: $type,
    ))->toThrow(VippsConfigException::class, 'A RECURRING charge requires both due and retryDays.');
})->with([
    'missing due' => [null, 5, ChargeType::Recurring],
    'missing retryDays' => ['2026-10-01', null, ChargeType::Recurring],
    'omitted type defaults to RECURRING' => [null, null, null],
]);

it('rejects an UNSCHEDULED charge carrying a due date', function () {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        due: '2026-10-01',
        type: ChargeType::Unscheduled,
    ))->toThrow(VippsConfigException::class, 'An UNSCHEDULED charge must not set due');
});

it('rejects positive retryDays on an UNSCHEDULED charge — the spec allows only omitted or 0', function () {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        retryDays: 1,
        type: ChargeType::Unscheduled,
    ))->toThrow(VippsConfigException::class, 'UNSCHEDULED charge allows retryDays only omitted or 0');
});

it('accepts retryDays 0 on an UNSCHEDULED charge', function () {
    $charge = new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        retryDays: 0,
        type: ChargeType::Unscheduled,
    );

    expect($charge->toPayload()['retryDays'])->toBe(0);
});

it('rejects a due date that matches the ISO shape but is not a real calendar day', function (string $due) {
    expect(fn() => new NewCharge(
        amount: Amount::fromMinor(1000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'x',
        due: $due,
        retryDays: 2,
    ))->toThrow(VippsConfigException::class, 'real calendar date');
})->with(['2026-02-30', '2026-13-01', '2026-04-31']);

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

it('rejects an initialCharge whose amount currency differs from the pricing currency', function () {
    // InitialCharge's payload has no currency field — Vipps reads its minor
    // units in the pricing currency, so a mismatch would charge EUR digits
    // as NOK. Caught at construction, before any request exists.
    expect(fn() => new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(9900, 'NOK')),
        interval: Interval::months(1),
        productName: 'Plan',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
        initialCharge: new InitialCharge(
            amount: Amount::fromMinor(9900, 'EUR'),
            transactionType: ChargeTransactionType::DirectCapture,
            description: 'First month',
        ),
    ))->toThrow(VippsConfigException::class, 'initialCharge amount must use the agreement pricing currency');
});

it('rejects a null interval unless pricing is FLEXIBLE', function () {
    expect(fn() => new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(9900)),
        interval: null,
        productName: 'Plan',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ))->toThrow(VippsConfigException::class, 'interval is required unless pricing is FLEXIBLE');
});

it('builds FLEXIBLE pricing from a bare currency: type and currency only, no amounts', function () {
    $pricing = Pricing::flexible('NOK');

    expect($pricing->type)->toBe(PricingType::Flexible)
        ->and($pricing->currency)->toBe('NOK')
        ->and($pricing->amount)->toBeNull()
        ->and($pricing->suggestedMaxAmount)->toBeNull()
        ->and($pricing->toPayload())->toBe(['type' => 'FLEXIBLE', 'currency' => 'NOK']);
});

it('rejects a FLEXIBLE pricing currency that is not an ISO 4217 code', function (string $currency) {
    expect(fn() => Pricing::flexible($currency))
        ->toThrow(VippsConfigException::class, 'three-letter ISO 4217 code');
})->with(['nok', 'KRONER', '']);

it('rejects an empty AgreementPatch', function () {
    expect(fn() => new AgreementPatch())
        ->toThrow(VippsConfigException::class, 'AgreementPatch is empty — set at least one field to change.');
});

it('still reads a missing pricing type as LEGACY, the documented default', function () {
    $pricing = Pricing::fromArray(['amount' => 100, 'currency' => 'EUR']);

    expect($pricing->type)->toBe(PricingType::Legacy)
        ->and($pricing->amount?->minorUnits)->toBe(100)
        ->and($pricing->amount?->currency)->toBe('EUR');
});

it('refuses an unknown pricing type instead of relabelling it LEGACY', function () {
    expect(fn() => Pricing::fromArray(['type' => 'BRAND_NEW', 'amount' => 100, 'currency' => 'EUR']))
        ->toThrow(VippsMalformedResponseException::class, 'BRAND_NEW');
});

it('maps FLEXIBLE pricing with the user-chosen maxAmount', function () {
    $pricing = Pricing::fromArray(['type' => 'FLEXIBLE', 'maxAmount' => 45000, 'currency' => 'NOK']);

    expect($pricing->type)->toBe(PricingType::Flexible)
        ->and($pricing->maxAmount?->minorUnits)->toBe(45000)
        ->and($pricing->maxAmount?->currency)->toBe('NOK')
        ->and($pricing->amount)->toBeNull()
        ->and($pricing->suggestedMaxAmount)->toBeNull();
});

it('refuses a malformed response currency instead of relabelling the money NOK', function (mixed $currency) {
    // The old fallback turned e.g. an invalid "sek" into an apparently valid
    // NOK amount — a wrong-currency price with no error anywhere.
    expect(fn() => Pricing::fromArray(['type' => 'LEGACY', 'amount' => 100, 'currency' => $currency]))
        ->toThrow(VippsMalformedResponseException::class, 'pricing.currency');
})->with([
    'lowercase' => 'nok',
    'not a code' => 'KRONER',
    'wrong type' => 42,
]);

it('throws when a pricing response is missing its currency', function () {
    expect(fn() => Pricing::fromArray(['type' => 'LEGACY', 'amount' => 100]))
        ->toThrow(VippsMalformedResponseException::class, 'pricing.currency');
});

it('refuses an interval response with an unknown unit instead of calling it monthly', function () {
    expect(fn() => Interval::fromArray(['unit' => 'FORTNIGHT', 'count' => 1]))
        ->toThrow(VippsMalformedResponseException::class, 'FORTNIGHT');
});

it('refuses an interval response with a missing or invalid unit or count', function (array $data, string $field) {
    expect(fn() => Interval::fromArray($data))
        ->toThrow(VippsMalformedResponseException::class, $field);
})->with([
    'missing unit' => [['count' => 1], 'interval.unit'],
    'non-string unit' => [['unit' => 3, 'count' => 1], 'interval.unit'],
    'missing count' => [['unit' => 'MONTH'], 'interval.count'],
    'non-integer count' => [['unit' => 'MONTH', 'count' => '2'], 'interval.count'],
    'zero count' => [['unit' => 'MONTH', 'count' => 0], 'interval.count'],
]);
