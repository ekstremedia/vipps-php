<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

use Nesthus\Vipps\Auth\InMemoryTokenCache;
use Nesthus\Vipps\Auth\TokenCacheInterface;
use Nesthus\Vipps\Auth\TokenProvider;
use Nesthus\Vipps\Epayment\EpaymentApi;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Http\AuthenticatedTransport;
use Nesthus\Vipps\Login\LoginApi;
use Nesthus\Vipps\Recurring\RecurringApi;
use Nesthus\Vipps\Support\SystemClock;
use Nesthus\Vipps\Webhooks\WebhooksApi;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Entry point: wires config + PSR-18/17 plumbing once, hands out the API
 * modules. Everything is lazy so constructing this costs nothing.
 *
 *     $vipps = new Vipps($config, $httpClient, $requestFactory, $streamFactory);
 *     $agreement = $vipps->recurring()->createAgreement($draft, idempotencyKey: $key);
 *
 * Inject an HTTP client WITH TIMEOUTS configured — PSR-18 clients tend to
 * wait forever by default, and this SDK refuses to guess a policy for you.
 */
final class Vipps
{
    public const VERSION = '0.1.0';

    private ?ApiTransport $transport = null;

    private ?AuthenticatedTransport $authenticated = null;

    private ?TokenProvider $tokenProvider = null;

    public function __construct(
        private readonly VippsConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly TokenCacheInterface $tokenCache = new InMemoryTokenCache(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function config(): VippsConfig
    {
        return $this->config;
    }

    public function tokens(): TokenProvider
    {
        return $this->tokenProvider ??= new TokenProvider(
            $this->transport(),
            $this->config,
            $this->tokenCache,
            $this->clock,
        );
    }

    public function recurring(): RecurringApi
    {
        return new RecurringApi($this->authenticatedTransport());
    }

    public function epayment(): EpaymentApi
    {
        return new EpaymentApi($this->authenticatedTransport());
    }

    public function login(): LoginApi
    {
        return new LoginApi($this->transport(), $this->config);
    }

    public function webhooks(): WebhooksApi
    {
        return new WebhooksApi($this->authenticatedTransport());
    }

    private function transport(): ApiTransport
    {
        return $this->transport ??= new ApiTransport(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->config,
        );
    }

    private function authenticatedTransport(): AuthenticatedTransport
    {
        return $this->authenticated ??= new AuthenticatedTransport($this->transport(), $this->tokens());
    }
}
