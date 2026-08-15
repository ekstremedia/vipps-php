<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Tests\Unit\Recurring;

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Http\ApiTransport;
use Nesthus\Vipps\Recurring\RecurringApi;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\VippsConfig;

/**
 * RecurringApi wired to the recording fake through a REAL ApiTransport, so
 * every test exercises path building, headers and JSON encoding for real —
 * not a mocked Transport that would pass no matter what the module sends.
 */
final readonly class RecurringHarness
{
    public FakeHttpClient $http;

    public RecurringApi $api;

    public function __construct()
    {
        $this->http = new FakeHttpClient();
        $factory = new HttpFactory();

        $this->api = new RecurringApi(new ApiTransport(
            $this->http,
            $factory,
            $factory,
            new VippsConfig(
                clientId: 'client-id',
                clientSecret: 'client-secret',
                subscriptionKey: 'subscription-key',
                merchantSerialNumber: '123456',
            ),
        ));
    }

    /**
     * Decoded JSON body of the last request sent.
     *
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        return (array) json_decode((string) $this->http->lastRequest()->getBody(), true);
    }
}
