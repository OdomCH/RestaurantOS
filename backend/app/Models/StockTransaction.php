<?php

namespace App\Models;

use App\Enums\StockTransactionType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The inventory system of record - append-only.
 *
 * Every movement at every branch: purchases, wastage, corrections, transfers and
 * the automatic deduction behind each sale. `inventories.quantity_on_hand` is a
 * cache of the sum of these rows and can always be rebuilt from them.
 *
 * `quantity_change` is signed and never zero. A mistake is corrected by adding a
 * compensating row, never by editing one - the AppendOnly trait, the database
 * grant and a BEFORE UPDATE trigger all enforce that.
 */
#[Fillable([
    'branch_id', 'ingredient_id', 'type', 'quantity_change', 'unit_id',
    'unit_cost', 'total_cost', 'balance_after', 'average_cost_after',
    'reference_type', 'reference_id', 'reason', 'performed_by', 'occurred_at',
])]
class StockTransaction extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'type' => StockTransactionType::class,
            'quantity_change' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'average_cost_after' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** NULL for system-generated rows such as a sale deduction. */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** The Order, StockTransfer or Refund that caused this movement. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }
}
