<?php

declare(strict_types=1);

use Nesthus\Vipps\Auth\AccessToken;
use Nesthus\Vipps\VippsConfig;
use Nesthus\Vipps\Webhooks\RegisteredWebhook;

/**
 * A secret must survive NONE of PHP's dump functions: __debugInfo() covers
 * var_dump() and print_r(), but var_export() ignores it and prints raw
 * properties — which is why each class stores its secret inside a
 * SensitiveParameterValue instead of a public property. These tests dump
 * through all three so a future "simplification" back to a plain property
 * fails here instead of leaking in someone's error page.
 *
 * @return array<string, string>
 */
function redactionDumps(object $object): array
{
    ob_start();
    var_dump($object);
    $dumped = (string) ob_get_clean();

    return [
        'print_r' => print_r($object, true),
        'var_export' => var_export($object, true),
        'var_dump' => $dumped,
    ];
}

describe('VippsConfig', function () {
    beforeEach(function () {
        $this->config = new VippsConfig(
            clientId: 'client-id',
            clientSecret: 'hush-client-secret',
            subscriptionKey: 'hush-subscription-key',
            merchantSerialNumber: '123456',
        );
    });

    it('keeps both secrets out of print_r, var_export and var_dump', function () {
        foreach (redactionDumps($this->config) as $output) {
            expect($output)->not->toContain('hush-client-secret')
                ->and($output)->not->toContain('hush-subscription-key');
        }
    });

    it('keeps the non-secrets readable in a dump', function () {
        expect(print_r($this->config, true))->toContain('client-id')
            ->toContain('123456')
            ->toContain('***redacted***');
    });

    it('reveals the secrets only to code that asks explicitly', function () {
        expect($this->config->clientSecret())->toBe('hush-client-secret')
            ->and($this->config->subscriptionKey())->toBe('hush-subscription-key');
    });
});

describe('AccessToken', function () {
    beforeEach(function () {
        $this->token = new AccessToken('hush-bearer-token', new DateTimeImmutable('2026-08-15 13:00:00'));
    });

    it('keeps the token value out of print_r, var_export and var_dump', function () {
        foreach (redactionDumps($this->token) as $output) {
            expect($output)->not->toContain('hush-bearer-token');
        }
    });

    it('keeps the expiry readable and reveals the value only via value()', function () {
        expect(print_r($this->token, true))->toContain('***redacted***')
            ->and($this->token->value())->toBe('hush-bearer-token')
            ->and($this->token->expiresAt->format('H:i'))->toBe('13:00');
    });
});

describe('RegisteredWebhook', function () {
    beforeEach(function () {
        $this->hook = RegisteredWebhook::fromArray(['id' => 'wh-1', 'secret' => 'hush-signing-secret']);
    });

    it('keeps the signing secret out of print_r, var_export and var_dump', function () {
        foreach (redactionDumps($this->hook) as $output) {
            expect($output)->not->toContain('hush-signing-secret');
        }
    });

    it('keeps the id readable and reveals the secret only via secret()', function () {
        expect(print_r($this->hook, true))->toContain('wh-1')
            ->toContain('***redacted***')
            ->and($this->hook->secret())->toBe('hush-signing-secret');
    });
});
