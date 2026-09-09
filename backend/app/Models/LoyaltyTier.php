<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bronze, Silver, Gold. Promotion is driven by lifetime points earned, which
 * never decrease - spending points must not demote anyone.
 */
#[Fillable([
    'name', 'min_lifetime_points', 'earn_rate_multiplier',
    'discount_percent', 'benefits', 'sort_order', 'is_active',
])]
class LoyaltyTier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'earn_rate_multiplier' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
