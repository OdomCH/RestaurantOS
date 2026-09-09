<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient line of a recipe: Milk, 0.20, L.
 *
 * `unit_id` may differ from the ingredient's stock unit; the exploder converts
 * before deducting, and refuses a cross-family conversion.
 */
#[Fillable([
    'recipe_id', 'ingredient_id', 'quantity', 'unit_id',
    'wastage_percent', 'is_optional', 'sort_order',
])]
class RecipeItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'wastage_percent' => 'decimal:2',
            'is_optional' => 'boolean',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Quantity including expected preparation loss. */
    public function effectiveQuantity(): float
    {
        return (float) $this->quantity * (1 + ((float) $this->wastage_percent / 100));
    }
}
