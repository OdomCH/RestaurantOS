<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a product is made of, and therefore what selling it consumes.
 *
 * Versioned: editing a live recipe would silently change the COGS of items
 * already costed, so a change creates a new version and retires the old one.
 * `product_variant_id` null means "applies to every size"; a variant-specific
 * recipe wins over it.
 */
#[Fillable(['product_id', 'product_variant_id', 'version', 'yield_quantity', 'is_active', 'notes'])]
class Recipe extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:3',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->with('ingredient');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
