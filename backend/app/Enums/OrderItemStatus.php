<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `order_items.status`. A voided line keeps its row — and therefore its
 * historical price snapshot — but is excluded from totals and from the recipe
 * explosion.
 */
enum OrderItemStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Voided = 'voided';
}
