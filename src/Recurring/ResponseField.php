<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use DateTimeImmutable;
use Exception;

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
     * Falls back to NOK when the currency is missing or malformed, so
     * Amount's own ISO 4217 validation can never throw while merely READING
     * a response — that guard exists to catch merchant mistakes on the way
     * out, not Vipps quirks on the way in.
     *
     * @param array<mixed> $data
     */
    public static function currency(array $data): string
    {
        $value = $data['currency'] ?? null;

        return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'NOK';
    }
}
