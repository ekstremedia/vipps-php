<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Webhooks;

use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The register() response — and the ONE AND ONLY time Vipps ever shows the
 * signing secret.
 *
 * ⚠️  THE SECRET IS RETURNED EXACTLY ONCE. Vipps never re-shows it: all()
 * returns id/url/events only, and there is no "reveal secret" endpoint.
 * Persist secret() (encrypted, next to the webhook id) BEFORE doing anything
 * else with this object — if storage fails, the only recovery is delete() and
 * register() again for a fresh secret. Losing it silently means every future
 * delivery fails signature validation with nothing in any log explaining why.
 *
 * The secret is a method over SensitiveParameterValue, not a public property:
 * __debugInfo() alone only covers var_dump()/print_r() — var_export() ignores
 * it — so the raw value must not sit in a property at all. Only code that
 * explicitly asks via secret() ever sees it.
 */
final readonly class RegisteredWebhook
{
    private SensitiveParameterValue $secret;

    public function __construct(
        public string $id,
        #[SensitiveParameter]
        string $secret,
    ) {
        $this->secret = new SensitiveParameterValue($secret);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'secret' => '***redacted***',
        ];
    }

    /**
     * A registration response without id or secret is useless — an '' secret
     * copied into config would make every future delivery fail validation
     * with nothing explaining why, so fail loudly at the source instead.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $secret = $data['secret'] ?? null;

        if (! is_string($id) || $id === '') {
            throw VippsMalformedResponseException::missingField('webhook registration', 'id');
        }

        if (! is_string($secret) || $secret === '') {
            throw VippsMalformedResponseException::missingField('webhook registration', 'secret');
        }

        return new self($id, $secret);
    }

    public function secret(): string
    {
        /** @var string $secret */
        $secret = $this->secret->getValue();

        return $secret;
    }
}
