<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `orders.order_type`. `table_number` is only meaningful for `dine_in`.
 */
enum OrderType: string
{
    use HasValues;

    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';
}
