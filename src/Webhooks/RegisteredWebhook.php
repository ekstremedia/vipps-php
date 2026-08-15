<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

/**
 * The register() response — and the ONE AND ONLY time Vipps ever shows the
 * signing secret.
 *
 * ⚠️  THE SECRET IS RETURNED EXACTLY ONCE. Vipps never re-shows it: all()
 * returns id/url/events only, and there is no "reveal secret" endpoint.
 * Persist it (encrypted, next to the webhook id) BEFORE doing anything else
 * with this object — if storage fails, the only recovery is delete() and
 * register() again for a fresh secret. Losing it silently means every future
 * delivery fails signature validation with nothing in any log explaining why.
 */
final readonly class RegisteredWebhook
{
    public function __construct(
        public string $id,
        public string $secret,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $secret = $data['secret'] ?? null;

        return new self(
            is_string($id) ? $id : '',
            is_string($secret) ? $secret : '',
        );
    }
}
