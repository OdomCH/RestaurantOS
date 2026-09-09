<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `payments.status` — the status of one payment record, which is not the same
 * thing as the payment status of the order (see OrderPaymentStatus).
 *
 * A payment row is never deleted: a mistaken payment becomes `voided`, and a
 * settled one that is given back becomes `refunded` / `partially_refunded`.
 */
enum PaymentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Voided = 'voided';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Statuses that contribute to `orders.paid_total` (C6).
     *
     * @return array<int, string>
     */
    public static function settledValues(): array
    {
        return [
            self::Captured->value,
            self::PartiallyRefunded->value,
            self::Refunded->value,
        ];
    }
}
