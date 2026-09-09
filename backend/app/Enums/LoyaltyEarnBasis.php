<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `loyalty_rules.earn_basis` — which figure on the order earns points.
 *
 * The choice is a real business decision: earning on `grand_total` pays points
 * on tax and service charge, which most operators do not intend.
 */
enum LoyaltyEarnBasis: string
{
    use HasValues;

    case Subtotal = 'subtotal';
    case NetOfDiscount = 'net_of_discount';
    case GrandTotal = 'grand_total';
}
