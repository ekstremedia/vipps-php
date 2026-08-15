<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

use Nesthus\Vipps\Exceptions\VippsConfigException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Immutable credentials + environment for one sales unit.
 *
 * All four credential values come from the merchant portal's developer
 * section, per sales unit and per environment — test keys only ever work
 * against the test host and vice versa, which is why the environment lives
 * here next to the keys instead of being a per-call switch.
 *
 * The two secrets are methods over SensitiveParameterValue, not public
 * properties, and the whole difference is var_export(): __debugInfo() covers
 * var_dump() and print_r(), but var_export() ignores it and dumps raw
 * properties — only the engine-level wrapper keeps a secret out of all three
 * dump functions (and out of serialize(), which it refuses outright).
 * #[SensitiveParameter] does the same for stack traces.
 *
 * `$baseUrlOverride` exists for pointing the SDK at a local mock server in
 * integration tests; production code should always select via Environment.
 */
final readonly class VippsConfig
{
    private SensitiveParameterValue $clientSecret;

    private SensitiveParameterValue $subscriptionKey;

    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        #[SensitiveParameter]
        string $subscriptionKey,
        public string $merchantSerialNumber,
        public Environment $environment = Environment::Test,
        public SystemInfo $system = new SystemInfo('unknown', 'unknown'),
        public ?string $baseUrlOverride = null,
    ) {
        foreach ([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'subscriptionKey' => $subscriptionKey,
            'merchantSerialNumber' => $merchantSerialNumber,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw VippsConfigException::missing($name);
            }
        }

        if ($baseUrlOverride !== null && ! self::isAbsoluteHttpUrl($baseUrlOverride)) {
            throw new VippsConfigException('baseUrlOverride must be an absolute http(s) URL.');
        }

        $this->clientSecret = new SensitiveParameterValue($clientSecret);
        $this->subscriptionKey = new SensitiveParameterValue($subscriptionKey);
    }

    /**
     * Secrets show as ***redacted***, non-secrets stay readable so a dump is
     * still useful for debugging.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => '***redacted***',
            'subscriptionKey' => '***redacted***',
            'merchantSerialNumber' => $this->merchantSerialNumber,
            'environment' => $this->environment,
            'system' => $this->system,
            'baseUrlOverride' => $this->baseUrlOverride,
        ];
    }

    public function clientSecret(): string
    {
        /** @var string $secret */
        $secret = $this->clientSecret->getValue();

        return $secret;
    }

    public function subscriptionKey(): string
    {
        /** @var string $key */
        $key = $this->subscriptionKey->getValue();

        return $key;
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrlOverride ?? $this->environment->baseUrl(), '/');
    }

    /**
     * A real parse, not str_starts_with('http') — that prefix check accepted
     * "httpfoo://…" and even a bare "http". An override must carry an
     * http/https scheme AND a host to be a URL a PSR-17 factory can hit.
     */
    private static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['host'] !== ''
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}
