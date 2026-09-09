<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `orders.payment_status` — always **derived** from the captured payments and
 * refunds of an order (FR-PAY-006). Never set from a client request.
 */
enum OrderPaymentStatus: string
{
    use HasValues;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
