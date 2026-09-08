<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * kg, g, l, ml, pcs - self-referencing, so g knows it is 0.001 of kg.
 *
 * Conversion is legal only inside a family: grams to kilograms yes, millilitres
 * to grams never, because that needs a density the system does not model.
 */
#[Fillable(['code', 'name', 'family', 'base_unit_id', 'conversion_factor', 'precision_digits'])]
class Unit extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
        ];
    }

    /** NULL on a family's own base unit. */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(self::class, 'base_unit_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Whether a quantity in this unit can be converted into the given one. */
    public function isCompatibleWith(self $other): bool
    {
        return $this->family === $other->family;
    }
}
