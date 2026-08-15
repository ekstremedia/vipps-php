<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

/**
 * The two Vipps MobilePay API environments. Same paths on both hosts; test
 * ("MT") is a full sandbox with its own merchant keys and test users, so the
 * only thing that ever differs in this SDK is the host selected here.
 */
enum Environment: string
{
    case Test = 'test';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://apitest.vipps.no',
            self::Production => 'https://api.vipps.no',
        };
    }
}
