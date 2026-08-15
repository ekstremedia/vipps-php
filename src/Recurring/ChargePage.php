<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * One page of an agreement's charges. v3 pages this endpoint through
 * headers, not the body: hand continuationToken back to listCharges() to
 * fetch the next page. Null means Vipps sent no token — this page is the
 * last one.
 */
final readonly class ChargePage
{
    /**
     * @param list<Charge> $charges
     */
    public function __construct(
        public array $charges,
        public ?string $continuationToken = null,
    ) {}
}
