<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `stock_transactions.type`.
 *
 * The requirements name four user-facing movements — Stock In, Stock Out,
 * Adjustment, Transfer. The ledger stores finer types so that a sale deduction
 * can be told apart from deliberate wastage in reporting
 * (docs/14-inventory-workflow.md §3.1).
 */
enum StockTransactionType: string
{
    use HasValues;

    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case SaleDeduction = 'sale_deduction';
    case SaleReversal = 'sale_reversal';
    case Wastage = 'wastage';
    case CountCorrection = 'count_correction';

    /**
     * Types whose `quantity_change` must be negative.
     */
    public function isOutbound(): bool
    {
        return in_array($this, [
            self::StockOut,
            self::TransferOut,
            self::SaleDeduction,
            self::Wastage,
        ], true);
    }

    /**
     * Types that demand an operator-supplied `reason`.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [
            self::Adjustment,
            self::Wastage,
            self::CountCorrection,
        ], true);
    }
}
