<?php

namespace App\Models;

use App\Enums\KitchenTicketItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which order line sits on which ticket. Deliberately thin - the name, quantity
 * and note are read from the order item, so a correction is corrected once.
 */
#[Fillable(['kitchen_ticket_id', 'order_item_id'])]
class KitchenTicketItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => KitchenTicketItemStatus::class,
            'prepared_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
