<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

use Nesthus\Vipps\Http\Transport;

/**
 * Webhooks management: subscribe a callback URL to event types, list what is
 * registered, unsubscribe. Registration is per sales unit — the transport
 * already sends the Merchant-Serial-Number header — and Vipps caps how many
 * hooks a sales unit may hold, so integrations should all()-and-reuse rather
 * than re-register on every deploy.
 *
 * The response to register() is the ONLY time Vipps reveals the signing
 * secret (see RegisteredWebhook). Inbound deliveries are verified with
 * SignatureValidator, which is deliberately independent of this class: the
 * receiving endpoint usually lives in a different process than the code that
 * registered the hook.
 */
final readonly class WebhooksApi
{
    private const PATH = '/webhooks/v1/webhooks';

    public function __construct(private Transport $transport) {}

    /**
     * Registers $url for the given event types. PERSIST THE RETURNED SECRET
     * IMMEDIATELY — it is shown exactly once, never again (see
     * RegisteredWebhook). If storing it fails, delete() and re-register.
     *
     * @param list<string> $events event type identifiers, e.g. "epayments.payment.captured.v1"
     */
    public function register(string $url, array $events, string $idempotencyKey): RegisteredWebhook
    {
        $response = $this->transport->request(
            'POST',
            self::PATH,
            ['url' => $url, 'events' => $events],
            idempotencyKey: $idempotencyKey,
        );

        return RegisteredWebhook::fromArray($response->data);
    }

    /**
     * @return list<Webhook>
     */
    public function all(): array
    {
        $rows = $this->transport->request('GET', self::PATH)->data['webhooks'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $webhooks = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $webhooks[] = Webhook::fromArray($row);
            }
        }

        return $webhooks;
    }

    public function delete(string $id, string $idempotencyKey): void
    {
        $this->transport->request(
            'DELETE',
            self::PATH . '/' . rawurlencode($id),
            idempotencyKey: $idempotencyKey,
        );
    }
}
