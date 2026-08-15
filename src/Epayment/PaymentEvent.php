<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use DateTimeImmutable;
use Exception;
use Nesthus\Vipps\Amount;

/**
 * One entry in a payment's audit trail (GET …/events): what happened, for
 * how much, when, and whether it succeeded. The name stays a plain string
 * on purpose — the event vocabulary (CAPTURED, REFUNDED, CANCELLED, …) is
 * wider than PaymentState and Vipps extends it without notice, so an enum
 * would turn a new event type into a crash while reading history.
 * idempotencyKey echoes the merchant's own key back on merchant-initiated
 * events, which is what lets a reconciliation job match Vipps' trail
 * against its own records; Vipps-initiated events carry none.
 */
final readonly class PaymentEvent
{
    public function __construct(
        public string $name,
        public ?Amount $amount,
        public ?DateTimeImmutable $timestamp,
        public bool $success,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $idempotencyKey = $data['idempotencyKey'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            amount: AmountShape::fromField($data['amount'] ?? null),
            timestamp: self::parseTimestamp($data['timestamp'] ?? null),
            success: ($data['success'] ?? null) === true,
            idempotencyKey: is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
        );
    }

    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
