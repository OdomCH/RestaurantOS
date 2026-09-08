<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named tax rate, stored as a fraction (0.0700 = 7 %) with an effective date
 * range. The rate that applied to a sale is copied onto the order line, so
 * changing a rate here never rewrites a past receipt.
 */
#[Fillable(['code', 'name', 'rate', 'is_inclusive', 'is_active', 'effective_from', 'effective_to'])]
class TaxRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_inclusive' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
