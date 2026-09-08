<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stock balance of one ingredient at one branch.
 *
 * A cache of the ledger, not the truth: `stock_transactions` is the system of
 * record and this row is what the POS reads. The nightly reconciliation compares
 * the two and shouts rather than silently repairing, because a silent repair
 * hides the bug that caused the drift.
 */
#[Fillable(['branch_id', 'ingredient_id'])]
class Inventory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'in_transit_quantity' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_movement_at' => 'datetime',
            'last_counted_at' => 'datetime',
            'low_stock_notified_at' => 'datetime',
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

    /**
     * What can actually be sold. In-transit stock is excluded deliberately: it
     * is not on the shelf yet.
     */
    public function availableQuantity(): float
    {
        return (float) $this->quantity_on_hand - (float) $this->reserved_quantity;
    }

    public function valuation(): float
    {
        return (float) $this->quantity_on_hand * (float) $this->average_cost;
    }
}
