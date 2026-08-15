<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Exception;
use Nesthus\Vipps\Exceptions\VippsMalformedResponseException;

/**
 * Narrowing helpers for reading Vipps response arrays, so the tolerance
 * policy lives in exactly one place: absent, null and wrong-typed values all
 * read as null (Vipps adds and reshapes response fields without notice, and a
 * parsing fatal over a field we don't even use would be self-inflicted).
 *
 * @internal
 */
final readonly class ResponseField
{
    /**
     * @param array<mixed> $data
     */
    public static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function dateOrNull(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::stringOrNull($data, $key);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function arrayAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The one field the tolerance policy does NOT apply to: currency labels
     * every Amount built from the response, so guessing it would relabel
     * money — an invalid "SEK" silently becoming a valid-looking NOK amount
     * is worse than any exception. Absent or wrong-typed reads as missing;
     * a string that is not a three-letter ISO 4217 code is refused as-is.
     *
     * @param array<mixed> $data
     * @param string $field how the field is named in the exception (e.g. "pricing.currency" when $data is a nested object)
     */
    public static function currency(array $data, string $context, string $field = 'currency'): string
    {
        $value = $data['currency'] ?? null;

        if (! is_string($value) || $value === '') {
            throw VippsMalformedResponseException::missingField($context, $field);
        }

        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw VippsMalformedResponseException::unexpectedValue($context, $field, $value);
        }

        return $value;
    }
}
