<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

/**
 * One registered webhook subscription as Vipps lists it. Deliberately has no
 * secret property: Vipps only reveals the secret in the register() response
 * (see RegisteredWebhook), so a listing can never recover a lost one.
 */
final readonly class Webhook
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
    ) {}

    /**
     * Tolerates unknown extra keys and missing optionals — Vipps adds fields
     * without notice, and a listing must not break when they do.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $url = $data['url'] ?? null;
        $rawEvents = $data['events'] ?? null;

        $events = [];
        if (is_array($rawEvents)) {
            foreach ($rawEvents as $event) {
                if (is_string($event)) {
                    $events[] = $event;
                }
            }
        }

        return new self(
            is_string($id) ? $id : '',
            is_string($url) ? $url : '',
            $events,
        );
    }
}
