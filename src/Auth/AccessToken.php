<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * A bearer token with its expiry. Vipps tokens live 1 hour in test and 24
 * hours in production, and may overlap freely — so caching one until close
 * to expiry is always safe.
 *
 * The token string is a method over SensitiveParameterValue, not a public
 * property: whoever holds it IS the merchant, and __debugInfo() alone only
 * covers var_dump()/print_r() — var_export() ignores it, so the raw value
 * must not sit in a property at all. #[SensitiveParameter] keeps it out of
 * stack traces the same way.
 */
final readonly class AccessToken
{
    private SensitiveParameterValue $value;

    public function __construct(
        #[SensitiveParameter]
        string $value,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->value = new SensitiveParameterValue($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'value' => '***redacted***',
            'expiresAt' => $this->expiresAt,
        ];
    }

    public function value(): string
    {
        /** @var string $token */
        $token = $this->value->getValue();

        return $token;
    }

    /**
     * Fresh means "will still be valid for at least $marginSeconds" — the
     * margin absorbs clock skew and the time the actual API call needs, so a
     * token is never handed out that dies mid-request.
     */
    public function isFreshAt(DateTimeImmutable $now, int $marginSeconds = 60): bool
    {
        return $now->getTimestamp() + $marginSeconds < $this->expiresAt->getTimestamp();
    }
}
