<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Tests\Support\FakeHttpClient;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

beforeEach(function () {
    $this->client = new FakeHttpClient();
    $factory = new HttpFactory();

    $this->vipps = new Vipps(
        new VippsConfig('client-id', 'client-secret', 'sub-key', '123456'),
        $this->client,
        $factory,
        $factory,
    );
});

it('hands out the same LoginApi instance every time', function () {
    // Identity matters here: LoginApi memoizes the OIDC discovery document
    // per instance, so a fresh instance per call would silently re-fetch
    // discovery on every step of one login flow.
    expect($this->vipps->login())->toBe($this->vipps->login());
});

it('keeps discovery memoized across separate login() calls', function () {
    $this->client->queueJson(200, [
        'authorization_endpoint' => 'https://apitest.vipps.no/access-management-1.0/access/oauth2/auth',
    ]);

    $this->vipps->login()->configuration();
    $this->vipps->login()->configuration();

    expect($this->client->requests)->toHaveCount(1);
});
