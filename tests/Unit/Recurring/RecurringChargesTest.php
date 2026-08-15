<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsApiException;
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

it('lists charges as a plain GET, optionally filtered by status', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [recurringChargeBody('chr_1'), recurringChargeBody('chr_2')]);

    $charges = $h->api->listCharges('agr_5kSeqz');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges')
        ->and($request->getUri()->getQuery())->toBe('')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and($charges)->toHaveCount(2)
        ->and($charges[0]->id)->toBe('chr_1');

    $h->http->queueJson(200, [recurringChargeBody('chr_3', 'FAILED')]);

    $charges = $h->api->listCharges('agr_5kSeqz', ChargeStatus::Failed);

    expect($h->http->lastRequest()->getUri()->getQuery())->toBe('status=FAILED')
        ->and($charges[0]->status)->toBe(ChargeStatus::Failed);
});

it('maps a charge in full, tolerating unknown keys', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'chr_WCVbcA',
        'status' => 'PARTIALLY_REFUNDED',
        'amount' => 19900,
        'amountRefunded' => 5000,
        'currency' => 'NOK',
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
        ->and($charge->amountRefunded?->minorUnits)->toBe(5000)
        ->and($charge->description)->toBe('October box')
        ->and($charge->due?->format('Y-m-d'))->toBe('2026-10-01')
        ->and($charge->retryDays)->toBe(5)
        ->and($charge->transactionType)->toBe(ChargeTransactionType::DirectCapture)
        ->and($charge->failureReason)->toBeNull()
        ->and($charge->failureCode)->toBeNull();
});

it('maps failure details on a failed charge and leaves absent amounts null', function () {
    $h = new RecurringHarness();
    $h->http->queueJson(200, [
        'id' => 'chr_failed',
        'status' => 'FAILED',
        'amount' => 9900,
        'currency' => 'NOK',
        'description' => 'December box',
        'failureReason' => 'The payment failed in the app',
        'failureCode' => 'user_action_required',
    ]);

    $charge = $h->api->getChargeById('chr_failed');

    expect($charge->status)->toBe(ChargeStatus::Failed)
        ->and($charge->amountRefunded)->toBeNull()
        ->and($charge->due)->toBeNull()
        ->and($charge->retryDays)->toBeNull()
        ->and($charge->transactionType)->toBeNull()
        ->and($charge->failureReason)->toBe('The payment failed in the app')
        ->and($charge->failureCode)->toBe('user_action_required');
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

it('captures part of a reserved charge with amount and description', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->captureCharge(
        'agr_5kSeqz',
        'chr_WCVbcA',
        idempotencyKey: 'idem-capture-1',
        amount: Amount::fromMinor(10000),
        description: 'Partial delivery',
    );

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_5kSeqz/charges/chr_WCVbcA/capture')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-capture-1')
        ->and($h->lastJson())->toBe([
            'amount' => 10000,
            'description' => 'Partial delivery',
        ]);
});

it('captures the full reservation with an empty body', function () {
    $h = new RecurringHarness();
    $h->http->queueRaw(204);

    $h->api->captureCharge('agr_1', 'chr_1', idempotencyKey: 'idem-capture-2');

    $request = $h->http->lastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/recurring/v3/agreements/agr_1/charges/chr_1/capture')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-capture-2')
        ->and((string) $request->getBody())->toBe('')
        ->and($request->hasHeader('Content-Type'))->toBeFalse();
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

    expect(fn() => $h->api->captureCharge('agr_1', 'chr_1', idempotencyKey: 'idem-capture-3'))
        ->toThrow(function (VippsApiException $e) {
            expect($e->status)->toBe(409)
                ->and($e->details['detail'])->toBe('Charge already captured')
                ->and($e->traceId)->toBe('ctx-409');
        });
});
