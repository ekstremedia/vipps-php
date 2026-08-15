<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Recurring\AgreementPatch;
use Nesthus\Vipps\Recurring\AgreementStatus;
use Nesthus\Vipps\Recurring\ChargeTransactionType;
use Nesthus\Vipps\Recurring\InitialCharge;
use Nesthus\Vipps\Recurring\Interval;
use Nesthus\Vipps\Recurring\IntervalUnit;
use Nesthus\Vipps\Recurring\NewAgreement;
use Nesthus\Vipps\Recurring\Pricing;
use Nesthus\Vipps\Recurring\PricingType;
use Nesthus\Vipps\Tests\Unit\Recurring\RecurringHarness;

/**
 * @return array<string, mixed>
 */
function recurringAgreementBody(string $id, string $status = 'ACTIVE'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'pricing' => ['type' => 'LEGACY', 'amount' => 39900, 'currency' => 'NOK'],
        'interval' => ['unit' => 'MONTH', 'count' => 1],
        'productName' => 'Premier League subscription',
        'start' => '2026-01-01T00:00:00Z',
    ];
}

it('creates an agreement with the full v3 payload under an idempotency key', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, [
        'agreementId' => 'agr_5kSeqz',
        'vippsConfirmationUrl' => 'https://apitest.vipps.no/deeplink/vippsgateway?token=abc',
        'chargeId' => 'chr_initial', // set because the agreement carries an initialCharge
    ]);

    $created = $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(39900)),
        interval: Interval::months(1),
        productName: 'Premier League subscription',
        merchantRedirectUrl: 'https://example.com/subscriptions/return',
        merchantAgreementUrl: 'https://example.com/subscriptions/manage',
        productDescription: 'All matches, all season',
        phoneNumber: '4791234567',
        initialCharge: new InitialCharge(
            amount: Amount::fromMinor(19900),
            transactionType: ChargeTransactionType::DirectCapture,
            description: 'First month',
        ),
        scope: 'name phoneNumber',
        skipLandingPage: true,
        externalId: 'sub-42',
    ), idempotencyKey: 'idem-agreement-1');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-agreement-1')
        ->and($h->lastJson())->toBe([
            'pricing' => ['type' => 'LEGACY', 'currency' => 'NOK', 'amount' => 39900],
            'interval' => ['unit' => 'MONTH', 'count' => 1],
            'merchantRedirectUrl' => 'https://example.com/subscriptions/return',
            'merchantAgreementUrl' => 'https://example.com/subscriptions/manage',
            'productName' => 'Premier League subscription',
            'productDescription' => 'All matches, all season',
            'phoneNumber' => '4791234567',
            'initialCharge' => [
                'amount' => 19900,
                'transactionType' => 'DIRECT_CAPTURE',
                'description' => 'First month',
            ],
            'scope' => 'name phoneNumber',
            'skipLandingPage' => true,
            'externalId' => 'sub-42',
        ])
        ->and($created->agreementId)->toBe('agr_5kSeqz')
        ->and($created->vippsConfirmationUrl)->toBe('https://apitest.vipps.no/deeplink/vippsgateway?token=abc')
        ->and($created->chargeId)->toBe('chr_initial');
});

it('omits optional agreement fields entirely and supports VARIABLE pricing', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['agreementId' => 'agr_1', 'vippsConfirmationUrl' => 'https://example.test/confirm']);

    $created = $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::variable(Amount::fromMinor(30000, 'DKK')),
        interval: Interval::weeks(2),
        productName: 'Flexi plan',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ), idempotencyKey: 'idem-agreement-2');

    expect($h->lastJson())->toBe([
        'pricing' => ['type' => 'VARIABLE', 'currency' => 'DKK', 'suggestedMaxAmount' => 30000],
        'interval' => ['unit' => 'WEEK', 'count' => 2],
        'merchantRedirectUrl' => 'https://example.com/return',
        'merchantAgreementUrl' => 'https://example.com/manage',
        'productName' => 'Flexi plan',
    ])
        ->and($created->chargeId)->toBeNull(); // no initialCharge, so Vipps sends no chargeId
});

it('creates a FLEXIBLE agreement with pricing of type and currency only, omitting the interval', function () {
    // The v3 flexible model: no amount, no ceiling, and no fixed cadence —
    // the request payload must not invent any of them.
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['agreementId' => 'agr_flex', 'vippsConfirmationUrl' => 'https://example.test/confirm']);

    $created = $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::flexible('NOK'),
        interval: null,
        productName: 'Utility bill',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ), idempotencyKey: 'idem-agreement-flex');

    expect($h->lastJson())->toBe([
        'pricing' => ['type' => 'FLEXIBLE', 'currency' => 'NOK'],
        'merchantRedirectUrl' => 'https://example.com/return',
        'merchantAgreementUrl' => 'https://example.com/manage',
        'productName' => 'Utility bill',
    ])
        ->and($created->agreementId)->toBe('agr_flex');
});

it('still sends the interval on a FLEXIBLE agreement when the merchant provides one', function () {
    // Interval is optional for FLEXIBLE, not forbidden.
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['agreementId' => 'agr_flex2', 'vippsConfirmationUrl' => 'https://example.test/confirm']);

    $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::flexible('NOK'),
        interval: Interval::months(1),
        productName: 'Utility bill',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ), idempotencyKey: 'idem-agreement-flex2');

    expect($h->lastJson()['interval'])->toBe(['unit' => 'MONTH', 'count' => 1]);
});

it('throws when agreement creation answers 2xx without an agreementId or confirmation URL', function (array $body, string $field) {
    $h = new RecurringHarness();
    $h->http->queueJson(201, $body);

    expect(fn() => $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(9900)),
        interval: Interval::months(1),
        productName: 'Plan',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ), idempotencyKey: 'idem-agreement-bad'))
        ->toThrow(VippsMalformedResponseException::class, $field);
})->with([
    'missing agreementId' => [['vippsConfirmationUrl' => 'https://example.test/confirm'], 'agreementId'],
    'missing vippsConfirmationUrl' => [['agreementId' => 'agr_1'], 'vippsConfirmationUrl'],
]);

it('lists agreements as a plain GET with no idempotency key and no body', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringAgreementBody('agr_1'), recurringAgreementBody('agr_2')]);

    $agreements = $h->api->listAgreements();

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements')
        ->and($request->getUri()->getQuery())->toBe('')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and((string) $request->getBody())->toBe('')
        ->and($agreements)->toHaveCount(2)
        ->and($agreements[0]->id)->toBe('agr_1')
        ->and($agreements[1]->id)->toBe('agr_2');
});

it('filters the agreement list by status through the query string', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringAgreementBody('agr_9', 'STOPPED')]);

    $agreements = $h->api->listAgreements(AgreementStatus::Stopped);

    expect($h->http->lastRequest()->getUri()->getQuery())->toBe('status=STOPPED')
        ->and($agreements)->toHaveCount(1)
        ->and($agreements[0]->status)->toBe(AgreementStatus::Stopped);
});

it('passes pageNumber and pageSize through the agreement list query', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringAgreementBody('agr_1')]);

    $h->api->listAgreements(AgreementStatus::Active, pageNumber: 2, pageSize: 50);

    expect($h->http->lastRequest()->getUri()->getQuery())->toBe('status=ACTIVE&pageNumber=2&pageSize=50');

    $h->http->queueJson(200, [recurringAgreementBody('agr_2')]);

    $h->api->listAgreements(pageSize: 25);

    expect($h->http->lastRequest()->getUri()->getQuery())->toBe('pageSize=25');
});

it('maps an agreement response, tolerating unknown keys and missing optionals', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'agr_5kSeqz',
        'status' => 'EXPIRED',
        'pricing' => [
            'type' => 'VARIABLE',
            'suggestedMaxAmount' => 30000,
            'currency' => 'NOK',
            'maxAmount' => 45000, // the ceiling the user actually approved
        ],
        'interval' => ['unit' => 'YEAR', 'count' => 1],
        'productName' => 'Yearbook',
        'start' => '2026-02-01T09:30:00Z',
        'uuid' => 'a-brand-new-field',            // unknown key
        'campaign' => ['type' => 'whatever'],     // unknown key
        // productDescription, externalId and stop are absent
    ]);

    $agreement = $h->api->getAgreement('agr_5kSeqz');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($agreement->id)->toBe('agr_5kSeqz')
        ->and($agreement->status)->toBe(AgreementStatus::Expired)
        ->and($agreement->pricing->type)->toBe(PricingType::Variable)
        ->and($agreement->pricing->suggestedMaxAmount?->minorUnits)->toBe(30000)
        ->and($agreement->pricing->maxAmount?->minorUnits)->toBe(45000)
        ->and($agreement->pricing->amount)->toBeNull()
        ->and($agreement->interval->unit)->toBe(IntervalUnit::Year)
        ->and($agreement->interval->count)->toBe(1)
        ->and($agreement->productName)->toBe('Yearbook')
        ->and($agreement->productDescription)->toBeNull()
        ->and($agreement->externalId)->toBeNull()
        ->and($agreement->start?->format('Y-m-d H:i'))->toBe('2026-02-01 09:30')
        ->and($agreement->stop)->toBeNull();
});

it('throws on an unknown agreement status instead of a bare ValueError', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, recurringAgreementBody('agr_1', 'BRAND_NEW_STATUS'));

    expect(fn() => $h->api->getAgreement('agr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'BRAND_NEW_STATUS');
});

it('throws when an agreement response is missing its status', function () {
    $h = new RecurringHarness();
    $body = recurringAgreementBody('agr_1');
    unset($body['status']);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getAgreement('agr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'status');
});

it('throws when an agreement response is missing its id or productName', function (string $field) {
    // Neither can be defaulted: the id is the only handle for addressing the
    // agreement again, and productName is what the user approved paying for.
    $h = new RecurringHarness();
    $body = recurringAgreementBody('agr_1');
    unset($body[$field]);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getAgreement('agr_1'))
        ->toThrow(VippsMalformedResponseException::class, $field);
})->with(['id', 'productName']);

it('throws when a non-FLEXIBLE agreement response has no interval', function () {
    $h = new RecurringHarness();
    $body = recurringAgreementBody('agr_1');
    unset($body['interval']);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getAgreement('agr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'interval');
});

it('maps a FLEXIBLE agreement response with no interval to a null interval', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'agr_flex',
        'status' => 'ACTIVE',
        'pricing' => ['type' => 'FLEXIBLE', 'currency' => 'NOK'],
        'productName' => 'Utility bill',
    ]);

    $agreement = $h->api->getAgreement('agr_flex');

    expect($agreement->pricing->type)->toBe(PricingType::Flexible)
        ->and($agreement->interval)->toBeNull();
});

it('throws on an unrepresentable interval in an agreement response instead of calling it monthly', function () {
    $h = new RecurringHarness();
    $body = recurringAgreementBody('agr_1');
    $body['interval'] = ['unit' => 'FORTNIGHT', 'count' => 1];
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getAgreement('agr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'FORTNIGHT');
});

it('stops an agreement with a STOPPED patch under an idempotency key', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->stopAgreement('agr_5kSeqz', idempotencyKey: 'idem-stop-1');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('PATCH')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-stop-1')
        ->and($h->lastJson())->toBe(['status' => 'STOPPED']);
});

it('updates product name and pricing through a PATCH', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->updateAgreement('agr_5kSeqz', new AgreementPatch(
        productName: 'Premier League++',
        price: Amount::fromMinor(44900),
    ), idempotencyKey: 'idem-update-1');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('PATCH')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-update-1')
        ->and($h->lastJson())->toBe([
            'productName' => 'Premier League++',
            'pricing' => ['amount' => 44900],
        ]);
});

it('surfaces a Vipps 409 as VippsApiException with the problem details', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(409, [
        'type' => 'https://developer.vippsmobilepay.com/docs/APIs/recurring-api/',
        'title' => 'Conflict',
        'detail' => 'An agreement with this idempotency key already exists',
        'contextId' => 'ctx-123',
    ]);

    expect(fn() => $h->api->createAgreement(new NewAgreement(
        pricing: Pricing::legacy(Amount::fromMinor(9900)),
        interval: Interval::months(1),
        productName: 'Duplicate',
        merchantRedirectUrl: 'https://example.com/return',
        merchantAgreementUrl: 'https://example.com/manage',
    ), idempotencyKey: 'idem-dup'))->toThrow(function (VippsApiException $e) {
        expect($e->status)->toBe(409)
            ->and($e->details['title'])->toBe('Conflict')
            ->and($e->details['detail'])->toBe('An agreement with this idempotency key already exists')
            ->and($e->traceId)->toBe('ctx-123')
            ->and($e->getMessage())->toContain('POST /recurring/v3/agreements')
            ->and($e->getMessage())->toContain('409');
    });
});
