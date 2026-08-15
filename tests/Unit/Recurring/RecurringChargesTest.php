<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsApiException;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use Nesthus\Vipps\Recurring\ChargeStatus;
use Nesthus\Vipps\Recurring\ChargeTransactionType;
use Nesthus\Vipps\Recurring\ChargeType;
use Nesthus\Vipps\Recurring\NewCharge;
use Nesthus\Vipps\Tests\Unit\Recurring\RecurringHarness;

/**
 * @return array<string, mixed>
 */
function recurringChargeBody(string $id, string $status = 'CHARGED'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'amount' => 19900,
        'currency' => 'NOK',
        'summary' => ['captured' => 19900, 'refunded' => 0, 'cancelled' => 0],
        'description' => 'October box',
        'due' => '2026-10-01T00:00:00Z',
        'retryDays' => 5,
        'transactionType' => 'DIRECT_CAPTURE',
    ];
}

it('creates a charge with the full payload and returns the charge id', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['chargeId' => 'chr_WCVbcA']);

    $chargeId = $h->api->createCharge('agr_5kSeqz', new NewCharge(
        amount: Amount::fromMinor(19900),
        transactionType: ChargeTransactionType::ReserveCapture,
        description: 'October box',
        due: new DateTimeImmutable('2026-10-01 15:30:00'),
        retryDays: 5,
        type: ChargeType::Recurring,
        externalId: 'chg-2026-10',
        orderId: 'order-771',
    ), idempotencyKey: 'idem-charge-1');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-charge-1')
        ->and($h->lastJson())->toBe([
            'amount' => 19900,
            'transactionType' => 'RESERVE_CAPTURE',
            'description' => 'October box',
            'due' => '2026-10-01',
            'retryDays' => 5,
            'type' => 'RECURRING',
            'externalId' => 'chg-2026-10',
            'orderId' => 'order-771',
        ])
        ->and($chargeId)->toBe('chr_WCVbcA');
});

it('accepts a preformatted due date string and omits optional charge fields', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['chargeId' => 'chr_2']);

    $h->api->createCharge('agr_1', new NewCharge(
        amount: Amount::fromMinor(5000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'November box',
        due: '2026-11-01',
        retryDays: 0,
    ), idempotencyKey: 'idem-charge-2');

    expect($h->lastJson())->toBe([
        'amount' => 5000,
        'transactionType' => 'DIRECT_CAPTURE',
        'description' => 'November box',
        'due' => '2026-11-01',
        'retryDays' => 0,
    ]);
});

it('creates an UNSCHEDULED charge with no due date in the payload', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['chargeId' => 'chr_topup']);

    $chargeId = $h->api->createCharge('agr_1', new NewCharge(
        amount: Amount::fromMinor(2500),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'One-off top-up',
        type: ChargeType::Unscheduled,
        externalId: 'topup-9',
    ), idempotencyKey: 'idem-charge-3');

    expect($h->lastJson())->toBe([
        'amount' => 2500,
        'transactionType' => 'DIRECT_CAPTURE',
        'description' => 'One-off top-up',
        'type' => 'UNSCHEDULED',
        'externalId' => 'topup-9',
    ])
        ->and($chargeId)->toBe('chr_topup');
});

it('throws when charge creation answers 2xx without a chargeId', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(201, ['somethingElse' => true]);

    expect(fn() => $h->api->createCharge('agr_1', new NewCharge(
        amount: Amount::fromMinor(5000),
        transactionType: ChargeTransactionType::DirectCapture,
        description: 'November box',
        due: '2026-11-01',
        retryDays: 0,
    ), idempotencyKey: 'idem-charge-4'))
        ->toThrow(VippsMalformedResponseException::class, 'chargeId');
});

it('lists charges as a plain GET, optionally filtered by status', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringChargeBody('chr_1'), recurringChargeBody('chr_2')]);

    $page = $h->api->listCharges('agr_5kSeqz');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges')
        ->and($request->getUri()->getQuery())->toBe('')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($request->hasHeader('Continuation-Token'))->toBeFalse()
        ->and($page->charges)->toHaveCount(2)
        ->and($page->charges[0]->id)->toBe('chr_1')
        ->and($page->continuationToken)->toBeNull();

    $h->http->queueJson(200, [recurringChargeBody('chr_3', 'FAILED')]);

    $page = $h->api->listCharges('agr_5kSeqz', ChargeStatus::Failed);

    expect($h->http->lastRequest()->getUri()->getQuery())->toBe('status=FAILED')
        ->and($page->charges[0]->status)->toBe(ChargeStatus::Failed);
});

it('pages charges through the Continuation-Token headers', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringChargeBody('chr_1')], ['Continuation-Token' => 'tok-page-2']);

    $page = $h->api->listCharges('agr_1', continuationToken: 'tok-page-1');

    expect($h->http->lastRequest()->getHeaderLine('Continuation-Token'))->toBe('tok-page-1')
        ->and($page->charges)->toHaveCount(1)
        ->and($page->continuationToken)->toBe('tok-page-2');

    // The last page answers without a token (or with an empty one) — both
    // must read as "stop paging".
    $h->http->queueJson(200, [recurringChargeBody('chr_2')], ['Continuation-Token' => '']);

    $page = $h->api->listCharges('agr_1', continuationToken: 'tok-page-2');

    expect($page->continuationToken)->toBeNull();
});

it('maps a charge in full, tolerating unknown keys', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'chr_WCVbcA',
        'status' => 'PARTIALLY_REFUNDED',
        'amount' => 19900,
        'currency' => 'NOK',
        'summary' => ['captured' => 19900, 'refunded' => 5000, 'cancelled' => 0],
        'description' => 'October box',
        'due' => '2026-10-01T00:00:00Z',
        'retryDays' => 5,
        'transactionType' => 'DIRECT_CAPTURE',
        'history' => [['occurred' => '2026-10-01T06:00:00Z']], // unknown key
        'externalAgreementId' => 'sub-42',                     // unknown key
    ]);

    $charge = $h->api->getCharge('agr_5kSeqz', 'chr_WCVbcA');

    expect($h->http->lastRequest()->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges/chr_WCVbcA')
        ->and($h->http->lastRequest()->getMethod())->toBe('GET')
        ->and($charge->id)->toBe('chr_WCVbcA')
        ->and($charge->status)->toBe(ChargeStatus::PartiallyRefunded)
        ->and($charge->amount->minorUnits)->toBe(19900)
        ->and($charge->amount->currency)->toBe('NOK')
        ->and($charge->summary->captured->minorUnits)->toBe(19900)
        ->and($charge->summary->refunded->minorUnits)->toBe(5000)
        ->and($charge->summary->cancelled->minorUnits)->toBe(0)
        ->and($charge->summary->refunded->currency)->toBe('NOK')
        ->and($charge->description)->toBe('October box')
        ->and($charge->due?->format('Y-m-d'))->toBe('2026-10-01')
        ->and($charge->retryDays)->toBe(5)
        ->and($charge->transactionType)->toBe(ChargeTransactionType::DirectCapture)
        ->and($charge->failureReason)->toBeNull()
        ->and($charge->failureDescription)->toBeNull();
});

it('maps summary.refunded so a double-refund guard can trust it', function () {
    // The guard scenario: before refunding, read the charge and check what
    // has ALREADY come back. v2 exposed amountRefunded at the top level; v3
    // only carries it inside summary — a mapping that misses it reads 0 and
    // refunds twice.
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'chr_1',
        'status' => 'REFUNDED',
        'amount' => 19900,
        'currency' => 'NOK',
        'summary' => ['captured' => 19900, 'refunded' => 19900, 'cancelled' => 0],
    ]);

    $charge = $h->api->getChargeById('chr_1');

    $alreadyRefunded = $charge->summary->refunded->minorUnits > 0;

    expect($alreadyRefunded)->toBeTrue()
        ->and($charge->summary->refunded->equals(Amount::fromMinor(19900)))->toBeTrue();
});

it('throws when a charge response is missing its amount instead of reading it as zero', function () {
    // Amount::fromMinor(0) is a perfectly valid money value — which is
    // exactly the problem: a caller would capture or refund zero minor units
    // with no error anywhere.
    $h = new RecurringHarness();
    $body = recurringChargeBody('chr_1');
    unset($body['amount']);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'amount');
});

it('throws when a charge response carries a malformed currency instead of relabelling it NOK', function () {
    $h = new RecurringHarness();
    $body = recurringChargeBody('chr_1');
    $body['currency'] = 'kroner';
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'currency');
});

it('throws when a charge response has no summary object', function () {
    $h = new RecurringHarness();
    $body = recurringChargeBody('chr_1');
    unset($body['summary']);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'summary');
});

it('throws when a summary total is missing instead of reading it as zero', function () {
    $h = new RecurringHarness();
    $body = recurringChargeBody('chr_1');
    $body['summary'] = ['captured' => 19900, 'cancelled' => 0]; // refunded absent
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'summary.refunded');
});

it('throws on an unknown charge status instead of a bare ValueError', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, recurringChargeBody('chr_1', 'BRAND_NEW_STATUS'));

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'BRAND_NEW_STATUS');
});

it('throws when a charge response is missing its status', function () {
    $h = new RecurringHarness();
    $body = recurringChargeBody('chr_1');
    unset($body['status']);
    $h->http->queueJson(200, $body);

    expect(fn() => $h->api->getChargeById('chr_1'))
        ->toThrow(VippsMalformedResponseException::class, 'status');
});

it('maps failure details on a failed charge and leaves absent optionals null', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'chr_failed',
        'status' => 'FAILED',
        'amount' => 9900,
        'currency' => 'NOK',
        'summary' => ['captured' => 0, 'refunded' => 0, 'cancelled' => 0],
        'description' => 'December box',
        'failureReason' => 'insufficient_funds',
        'failureDescription' => 'The payment failed in the app',
    ]);

    $charge = $h->api->getChargeById('chr_failed');

    expect($charge->status)->toBe(ChargeStatus::Failed)
        ->and($charge->summary->captured->minorUnits)->toBe(0)
        ->and($charge->due)->toBeNull()
        ->and($charge->retryDays)->toBeNull()
        ->and($charge->transactionType)->toBeNull()
        ->and($charge->failureReason)->toBe('insufficient_funds')
        ->and($charge->failureDescription)->toBe('The payment failed in the app');
});

it('fetches a charge by id alone on the fast path', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, recurringChargeBody('chr_9'));

    $charge = $h->api->getChargeById('chr_9');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/charges/chr_9')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($charge->id)->toBe('chr_9');
});

it('cancels a charge with DELETE under an idempotency key and no body', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->cancelCharge('agr_5kSeqz', 'chr_WCVbcA', idempotencyKey: 'idem-cancel-1');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges/chr_WCVbcA')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-cancel-1')
        ->and((string) $request->getBody())->toBe('');
});

it('captures part of a reserved charge with an explicit amount', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->captureCharge(
        'agr_5kSeqz',
        'chr_WCVbcA',
        Amount::fromMinor(10000),
        idempotencyKey: 'idem-capture-1',
    );

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges/chr_WCVbcA/capture')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-capture-1')
        ->and($h->lastJson())->toBe(['amount' => 10000]);
});

it('sends the amount even for a full capture — v3 has no empty-body form', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->captureCharge('agr_1', 'chr_1', Amount::fromMinor(19900), idempotencyKey: 'idem-capture-2');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_1/charges/chr_1/capture')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-capture-2')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($h->lastJson())->toBe(['amount' => 19900]);
});

it('refunds a charge with amount and description in minor units', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->refundCharge(
        'agr_5kSeqz',
        'chr_WCVbcA',
        Amount::fromMinor(19900),
        'Order cancelled',
        idempotencyKey: 'idem-refund-1',
    );

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges/chr_WCVbcA/refund')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-refund-1')
        ->and($h->lastJson())->toBe([
            'amount' => 19900,
            'description' => 'Order cancelled',
        ]);
});

it('surfaces a 409 on capture as VippsApiException with details', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(409, [
        'title' => 'Conflict',
        'detail' => 'Charge already captured',
        'contextId' => 'ctx-409',
    ]);

    expect(fn() => $h->api->captureCharge('agr_1', 'chr_1', Amount::fromMinor(19900), idempotencyKey: 'idem-capture-3'))
        ->toThrow(function (VippsApiException $e) {
            expect($e->status)->toBe(409)
                ->and($e->details['detail'])->toBe('Charge already captured')
                ->and($e->traceId)->toBe('ctx-409');
        });
});
