<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `orders.discount_type` and `order_items.discount_type`.
 *
 * `discount_value` is the input (a percentage or a fixed amount); the resulting
 * money reduction is stored separately in `discount_amount`.
 */
enum DiscountType: string
{
    use HasValues;

    case None = 'none';
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
