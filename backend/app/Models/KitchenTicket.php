<?php

namespace App\Models;

use App\Enums\KitchenTicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One order's work for one station.
 *
 * Not a copy of the order: it holds routing, timing and its own status, and
 * joins back to order_items for what to make. An order with drinks and food has
 * two tickets that finish independently, which a single order status cannot
 * express.
 */
#[Fillable(['kitchen_station_id', 'priority'])]
class KitchenTicket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => KitchenTicketStatus::class,
            'priority' => 'integer',
            'sla_minutes' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }

    /** The KDS poll: open tickets for a branch, oldest first. */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', KitchenTicketStatus::openValues());
    }

    /** Past its SLA and still not ready. */
    public function isLate(): bool
    {
        return $this->ready_at === null
            && $this->queued_at?->addMinutes($this->sla_minutes)->isPast() === true;
    }
}
