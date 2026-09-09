<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milk, Coffee Beans, Sugar, Cheese, Chicken.
 *
 * Master data only - an ingredient has no stock level of its own. Ask
 * `inventories` for the balance at a branch; there is deliberately no global
 * quantity anywhere.
 */
#[Fillable([
    'code', 'name', 'unit_id', 'category', 'default_cost_per_unit',
    'reorder_level', 'reorder_quantity', 'is_perishable', 'shelf_life_days',
    'is_active',
])]
class Ingredient extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'default_cost_per_unit' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'is_perishable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** The stock-keeping unit: balances are always held in this unit. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function transferItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /** Balance at one branch, or null if the branch has never held it. */
    public function inventoryAt(int $branchId): ?Inventory
    {
        return $this->inventories()->where('branch_id', $branchId)->first();
    }
}
