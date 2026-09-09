<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `payments.method`, `refunds.method`, `expenses.payment_method`.
 *
 * Stored as `VARCHAR(20)` rather than a MySQL `ENUM` precisely so that a future
 * method (e-wallet, voucher) is a seeder change, not an `ALTER TABLE` that locks
 * a nine-hundred-thousand-row table.
 */
enum PaymentMethod: string
{
    use HasValues;

    case Cash = 'cash';
    case Card = 'card';
    case Qr = 'qr';
    case BankTransfer = 'bank_transfer';

    /**
     * Non-cash methods must carry an external `reference` (FR-PAY-005).
     */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * Only cash records `tendered_amount` and `change_amount`.
     */
    public function acceptsTender(): bool
    {
        return $this === self::Cash;
    }
}
