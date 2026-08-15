<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Exceptions;

use RuntimeException;

/**
 * Vipps answered 2xx but the body does not match the documented contract —
 * a required field is missing, or an enum value is one we do not know.
 *
 * Exists so that "Vipps sent something weird" still lands in a
 * `catch (VippsException)` boundary: mapping DTOs used to let native
 * ValueError/JsonException escape, which silently broke the SDK's promise
 * that everything it throws implements the marker interface.
 */
final class VippsMalformedResponseException extends RuntimeException implements VippsException
{
    public static function missingField(string $context, string $field): self
    {
        return new self("Vipps response for {$context} is missing required field \"{$field}\".");
    }

    public static function unexpectedValue(string $context, string $field, string $value): self
    {
        return new self("Vipps response for {$context} carries unknown {$field} \"{$value}\" — SDK update likely needed.");
    }
}
