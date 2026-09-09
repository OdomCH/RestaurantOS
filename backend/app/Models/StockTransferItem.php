<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transfer line. Four quantities rather than one, because requested, approved,
 * dispatched and received are four different facts and the gaps between them are
 * exactly what a manager needs to see.
 */
#[Fillable(['ingredient_id', 'unit_id', 'requested_quantity'])]
class StockTransferItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'approved_quantity' => 'decimal:4',
            'dispatched_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function hasVariance(): bool
    {
        return $this->variance_quantity !== null && (float) $this->variance_quantity !== 0.0;
    }
}
