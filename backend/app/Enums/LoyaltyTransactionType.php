<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `loyalty_transactions.type` (docs/16-customer-loyalty.md).
 *
 * `points` is signed, so the type never has to be consulted to know the
 * direction of a movement — it explains *why* the movement happened.
 */
enum LoyaltyTransactionType: string
{
    use HasValues;

    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjust = 'adjust';
    case Expire = 'expire';
    case Reverse = 'reverse';

    /**
     * Types that must debit the balance (negative `points`).
     */
    public function isDebit(): bool
    {
        return in_array($this, [self::Redeem, self::Expire], true);
    }

    /**
     * A manual adjustment is meaningless without a stated reason.
     */
    public function requiresReason(): bool
    {
        return $this === self::Adjust;
    }
}
