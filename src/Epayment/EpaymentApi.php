<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Http\Transport;

/**
 * Vipps ePayment API v1: single one-off payments.
 *
 * The lifecycle is reserve-then-capture. A payment the customer approves
 * lands in AUTHORIZED, which only HOLDS the money — capture() is what moves
 * it, in full or in parts (ship half the order, capture half), and an
 * authorization nobody captures expires on its own. cancel() releases the
 * hold early; refund() returns money already captured.
 *
 * The reference is the merchant's own idempotent identity for the payment,
 * distinct from the per-request Idempotency-Key: creating a second payment
 * with a used reference is answered with a 409, and that failure is the
 * point — it is what stops two racing checkouts from charging one order
 * twice. Never generate a fresh reference to "get past" a 409.
 */
final readonly class EpaymentApi
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function createPayment(CreatePayment $payment, string $idempotencyKey): CreatedPayment
    {
        $response = $this->transport->request(
            'POST',
            '/epayment/v1/payments',
            $payment->toPayload(),
            idempotencyKey: $idempotencyKey,
        );

        /** @var array<string, mixed> $data */
        $data = $response->data;

        return CreatedPayment::fromArray($data);
    }

    public function getPayment(string $reference): Payment
    {
        $response = $this->transport->request('GET', $this->path($reference));

        /** @var array<string, mixed> $data */
        $data = $response->data;

        return Payment::fromArray($data);
    }

    /**
     * The payment's full audit trail, oldest first — the source of truth
     * when state + aggregates are not enough (e.g. which capture failed).
     *
     * @return list<PaymentEvent>
     */
    public function getEvents(string $reference): array
    {
        $response = $this->transport->request('GET', $this->path($reference) . '/events');

        $events = [];
        foreach ($response->data as $event) {
            if (! is_array($event)) {
                continue;
            }

            /** @var array<string, mixed> $event */
            $events[] = PaymentEvent::fromArray($event);
        }

        return $events;
    }

    public function capture(string $reference, Amount $amount, string $idempotencyKey): void
    {
        $this->transport->request(
            'POST',
            $this->path($reference) . '/capture',
            ['modificationAmount' => AmountShape::toArray($amount)],
            idempotencyKey: $idempotencyKey,
        );
    }

    public function cancel(string $reference, string $idempotencyKey): void
    {
        $this->transport->request('POST', $this->path($reference) . '/cancel', idempotencyKey: $idempotencyKey);
    }

    public function refund(string $reference, Amount $amount, string $idempotencyKey): void
    {
        $this->transport->request(
            'POST',
            $this->path($reference) . '/refund',
            ['modificationAmount' => AmountShape::toArray($amount)],
            idempotencyKey: $idempotencyKey,
        );
    }

    private function path(string $reference): string
    {
        return '/epayment/v1/payments/' . rawurlencode($reference);
    }
}
