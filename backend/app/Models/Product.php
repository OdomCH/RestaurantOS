<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable menu item: Cappuccino.
 *
 * Price resolution has three layers, cheapest first:
 *   branch_product.price_override, else products.base_price
 *   + product_variants.price_delta for the chosen size.
 * The single authoritative statement of that formula is
 * docs/09-business-rules.md 3 - do not re-derive it in a controller.
 */
#[Fillable([
    'category_id', 'tax_rate_id', 'sku', 'name', 'slug', 'description',
    'base_price', 'cost_price', 'image_path', 'is_active', 'is_available',
    'track_inventory', 'has_variants', 'preparation_minutes', 'sort_order',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'track_inventory' => 'boolean',
            'has_variants' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** NULL falls back to the branch default rate. */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Every recipe version, including retired ones. */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** The one recipe currently used for costing and deduction. */
    public function activeRecipe(): HasMany
    {
        return $this->hasMany(Recipe::class)->where('is_active', true);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Branches that override this product's price or availability. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product')
            ->withPivot(['price_override', 'is_available'])
            ->withTimestamps();
    }

    public function scopeSellable($query)
    {
        return $query->where('is_active', true)->where('is_available', true);
    }
}
