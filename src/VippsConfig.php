<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * Immutable credentials + environment for one sales unit.
 *
 * All four credential values come from the merchant portal's developer
 * section, per sales unit and per environment — test keys only ever work
 * against the test host and vice versa, which is why the environment lives
 * here next to the keys instead of being a per-call switch.
 *
 * `$baseUrlOverride` exists for pointing the SDK at a local mock server in
 * integration tests; production code should always select via Environment.
 */
final readonly class VippsConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $subscriptionKey,
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

        if ($baseUrlOverride !== null && ! str_starts_with($baseUrlOverride, 'http')) {
            throw new VippsConfigException('baseUrlOverride must be an absolute http(s) URL.');
        }
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrlOverride ?? $this->environment->baseUrl(), '/');
    }
}
