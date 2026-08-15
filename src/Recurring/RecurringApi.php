<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Http\Transport;

/**
 * Vipps MobilePay Recurring API v3: agreements (the user's standing mandate)
 * and charges (the individual money movements under it).
 *
 * Two operational rules every integrator trips on:
 *
 * 1. Vipps never bills anyone by itself. An ACTIVE agreement moves no money —
 *    the merchant creates every single charge (createCharge), at least one
 *    day before its due date, typically from a scheduled job. A missing or
 *    broken scheduler looks exactly like "Vipps stopped charging our
 *    customers".
 *
 * 2. Never treat the redirect back to merchantRedirectUrl as approval. Users
 *    approve and never return, or return without approving — poll
 *    getAgreement() until the status leaves PENDING (the user has 10
 *    minutes), or subscribe to the agreement webhooks.
 */
final readonly class RecurringApi
{
    private const BASE = '/recurring/v3';

    public function __construct(
        private Transport $transport,
    ) {}

    /**
     * Persist the returned agreementId together with $idempotencyKey BEFORE
     * redirecting the user to vippsConfirmationUrl — see rule 2 above.
     */
    public function createAgreement(NewAgreement $agreement, string $idempotencyKey): CreatedAgreement
    {
        $response = $this->transport->request(
            'POST',
            self::BASE . '/agreements',
            $agreement->toPayload(),
            idempotencyKey: $idempotencyKey,
        );

        return CreatedAgreement::fromArray($response->data);
    }

    /**
     * Vipps defaults to ACTIVE agreements when no status filter is given.
     *
     * @return list<Agreement>
     */
    public function listAgreements(?AgreementStatus $status = null): array
    {
        $query = $status !== null ? '?status=' . $status->value : '';

        $response = $this->transport->request('GET', self::BASE . '/agreements' . $query);

        $agreements = [];
        foreach ($response->data as $item) {
            if (is_array($item)) {
                $agreements[] = Agreement::fromArray($item);
            }
        }

        return $agreements;
    }

    public function getAgreement(string $agreementId): Agreement
    {
        $response = $this->transport->request('GET', $this->agreementPath($agreementId));

        return Agreement::fromArray($response->data);
    }

    /**
     * Not reversible: a STOPPED agreement can never be reactivated (the user
     * must approve a new one), and Vipps auto-cancels its pending charges.
     */
    public function stopAgreement(string $agreementId, string $idempotencyKey): void
    {
        $this->transport->request(
            'PATCH',
            $this->agreementPath($agreementId),
            ['status' => AgreementStatus::Stopped->value],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Price and text changes on a live agreement. Vipps notifies the user of
     * a price increase itself.
     */
    public function updateAgreement(string $agreementId, AgreementPatch $patch, string $idempotencyKey): void
    {
        $this->transport->request(
            'PATCH',
            $this->agreementPath($agreementId),
            $patch->toPayload(),
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Returns the new charge's id. This is THE call rule 1 in the class
     * docblock depends on — run it for every billing period, at least one
     * day before the charge's due date.
     */
    public function createCharge(string $agreementId, NewCharge $charge, string $idempotencyKey): string
    {
        $response = $this->transport->request(
            'POST',
            $this->agreementPath($agreementId) . '/charges',
            $charge->toPayload(),
            idempotencyKey: $idempotencyKey,
        );

        $chargeId = $response->data['chargeId'] ?? null;

        return is_string($chargeId) ? $chargeId : '';
    }

    /**
     * @return list<Charge>
     */
    public function listCharges(string $agreementId, ?ChargeStatus $status = null): array
    {
        $query = $status !== null ? '?status=' . $status->value : '';

        $response = $this->transport->request('GET', $this->agreementPath($agreementId) . '/charges' . $query);

        $charges = [];
        foreach ($response->data as $item) {
            if (is_array($item)) {
                $charges[] = Charge::fromArray($item);
            }
        }

        return $charges;
    }

    public function getCharge(string $agreementId, string $chargeId): Charge
    {
        $response = $this->transport->request('GET', $this->chargePath($agreementId, $chargeId));

        return Charge::fromArray($response->data);
    }

    /**
     * The by-id fast path (GET /charges/{id}): no agreement id needed, and
     * Vipps grants it higher rate limits than the per-agreement route —
     * prefer it in webhook handlers and polling loops.
     */
    public function getChargeById(string $chargeId): Charge
    {
        $response = $this->transport->request('GET', self::BASE . '/charges/' . rawurlencode($chargeId));

        return Charge::fromArray($response->data);
    }

    /**
     * Cancels a charge that has not been collected yet; once processing has
     * started this fails, and a refund is the remaining option.
     */
    public function cancelCharge(string $agreementId, string $chargeId, string $idempotencyKey): void
    {
        $this->transport->request(
            'DELETE',
            $this->chargePath($agreementId, $chargeId),
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Captures a RESERVED charge (RESERVE_CAPTURE) once the goods have
     * shipped. Null $amount captures the full reservation; a smaller one
     * captures partially and releases the remainder.
     */
    public function captureCharge(
        string $agreementId,
        string $chargeId,
        string $idempotencyKey,
        ?Amount $amount = null,
        ?string $description = null,
    ): void {
        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = $amount->minorUnits;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }

        $this->transport->request(
            'POST',
            $this->chargePath($agreementId, $chargeId) . '/capture',
            $payload === [] ? null : $payload,
            idempotencyKey: $idempotencyKey,
        );
    }

    public function refundCharge(
        string $agreementId,
        string $chargeId,
        Amount $amount,
        string $description,
        string $idempotencyKey,
    ): void {
        $this->transport->request(
            'POST',
            $this->chargePath($agreementId, $chargeId) . '/refund',
            [
                'amount' => $amount->minorUnits,
                'description' => $description,
            ],
            idempotencyKey: $idempotencyKey,
        );
    }

    private function agreementPath(string $agreementId): string
    {
        return self::BASE . '/agreements/' . rawurlencode($agreementId);
    }

    private function chargePath(string $agreementId, string $chargeId): string
    {
        return $this->agreementPath($agreementId) . '/charges/' . rawurlencode($chargeId);
    }
}
