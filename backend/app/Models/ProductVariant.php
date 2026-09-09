<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Small, Medium, Large.
 *
 * `price_delta` is signed and relative to the product's base price, so a
 * menu-wide increase is one update rather than one per variant per branch.
 */
#[Fillable([
    'product_id', 'sku', 'name', 'price_delta', 'cost_delta',
    'is_default', 'is_active', 'sort_order',
])]
class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'cost_delta' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Variant-specific recipes; a null-variant recipe is the fallback. */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
