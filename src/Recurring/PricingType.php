<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

/**
 * LEGACY (the default, and despite the name not deprecated): a fixed amount
 * per charge, shown to the user at approval. VARIABLE: the user approves a
 * ceiling (suggestedMaxAmount) instead of a fixed price, for usage-based
 * billing where every charge's amount differs — the ceiling they actually
 * approved comes back as the response's maxAmount. FLEXIBLE: v3's third
 * model, where charges vary under a user-chosen maxAmount without the
 * merchant fixing a price up front.
 */
enum PricingType: string
{
    case Legacy = 'LEGACY';
    case Variable = 'VARIABLE';
    case Flexible = 'FLEXIBLE';
}
