<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * LEGACY (the default, and despite the name not deprecated): a fixed amount
 * per charge, shown to the user at approval. VARIABLE: the user approves a
 * ceiling (suggestedMaxAmount) instead of a fixed price, for usage-based
 * billing where every charge's amount differs.
 */
enum PricingType: string
{
    case Legacy = 'LEGACY';
    case Variable = 'VARIABLE';
}
