<?php

declare(strict_types=1);

use Nesthus\Vipps\Environment;
use Nesthus\Vipps\Exceptions\VippsConfigException;
use Nesthus\Vipps\VippsConfig;

/**
 * @param array<string, string|null> $overrides
 */
function coreValidConfig(array $overrides = []): VippsConfig
{
    /** @var array{clientId: string, clientSecret: string, subscriptionKey: string, merchantSerialNumber: string, baseUrlOverride?: string|null} $args */
    $args = $overrides + [
        'clientId' => 'client-id',
        'clientSecret' => 'client-secret',
        'subscriptionKey' => 'sub-key',
        'merchantSerialNumber' => '123456',
    ];

    return new VippsConfig(...$args);
}

describe('credential validation', function () {
    it('throws naming the missing field', function (string $field) {
        try {
            coreValidConfig([$field => '']);
            $this->fail('Expected VippsConfigException was not thrown.');
        } catch (VippsConfigException $e) {
            expect($e->getMessage())->toContain($field);
        }
    })->with(['clientId', 'clientSecret', 'subscriptionKey', 'merchantSerialNumber']);

    it('treats a whitespace-only credential as missing', function () {
        coreValidConfig(['clientSecret' => "  \t "]);
    })->throws(VippsConfigException::class, 'clientSecret');

    it('accepts a complete set of credentials', function () {
        expect(coreValidConfig()->merchantSerialNumber)->toBe('123456');
    });
});

describe('baseUrl', function () {
    it('defaults to the test environment host', function () {
        expect(coreValidConfig()->baseUrl())->toBe('https://apitest.vipps.no');
    });

    it('follows the configured environment', function () {
        $config = new VippsConfig('id', 'secret', 'key', 'msn', Environment::Production);

        expect($config->baseUrl())->toBe('https://api.vipps.no');
    });

    it('lets the override win over the environment', function () {
        $config = new VippsConfig('id', 'secret', 'key', 'msn', Environment::Production, baseUrlOverride: 'http://localhost:8080');

        expect($config->baseUrl())->toBe('http://localhost:8080');
    });

    it('trims a trailing slash off the override', function () {
        $config = coreValidConfig(['baseUrlOverride' => 'http://localhost:8080/']);

        expect($config->baseUrl())->toBe('http://localhost:8080');
    });

    it('rejects a relative override', function () {
        coreValidConfig(['baseUrlOverride' => '/mock-server']);
    })->throws(VippsConfigException::class, 'absolute http(s)');

    it('rejects a non-http scheme', function () {
        coreValidConfig(['baseUrlOverride' => 'ftp://mock.test']);
    })->throws(VippsConfigException::class, 'absolute http(s)');

    it('rejects a scheme that merely starts with "http"', function () {
        // The old str_starts_with('http') check waved this through.
        coreValidConfig(['baseUrlOverride' => 'httpfoo://mock.test']);
    })->throws(VippsConfigException::class, 'absolute http(s)');

    it('rejects an http URL with no host', function (string $override) {
        coreValidConfig(['baseUrlOverride' => $override]);
    })->with([
        'scheme only' => ['http:'],
        'empty authority' => ['http://'],
        'bare word' => ['http'],
    ])->throws(VippsConfigException::class, 'absolute http(s)');
});
