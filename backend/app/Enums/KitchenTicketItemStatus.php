<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `kitchen_ticket_items.status`.
 *
 * A ticket becomes `ready` only once every non-cancelled item on it is `ready`.
 */
enum KitchenTicketItemStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Cancelled = 'cancelled';
}
