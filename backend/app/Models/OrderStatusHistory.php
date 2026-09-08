<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: every transition an order made, who made it and why.
 *
 * The evidence behind every SLA figure, and the only place a cancellation reason
 * survives.
 */
#[Fillable(['order_id', 'from_status', 'to_status', 'changed_by', 'reason', 'duration_seconds'])]
class OrderStatusHistory extends Model
{
    use AppendOnly;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** NULL for system transitions. */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
