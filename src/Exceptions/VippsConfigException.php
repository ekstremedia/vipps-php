<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Exceptions;

use InvalidArgumentException;

/**
 * Invalid SDK input: missing credentials, malformed amounts, bad arguments.
 * Always a programming/configuration error on the integrator's side — never
 * something the Vipps API said.
 */
final class VippsConfigException extends InvalidArgumentException implements VippsException
{
    public static function missing(string $field): self
    {
        return new self("Vipps configuration value \"{$field}\" is required but empty.");
    }
}
