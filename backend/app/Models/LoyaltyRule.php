<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The earn and redeem rates. Exactly one row is active at a time.
 *
 * "$1 spent = 1 point" is a row here, never a constant in code: an operator
 * changes the rate without a deployment, and a past order keeps the points it
 * actually earned.
 */
#[Fillable([
    'name', 'earn_points_per_currency_unit', 'earn_basis', 'redeem_value_per_point',
    'min_points_to_redeem', 'redeem_increment', 'max_redeem_percent_of_subtotal',
    'points_expiry_days', 'is_active', 'effective_from', 'effective_to',
])]
class LoyaltyRule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'earn_points_per_currency_unit' => 'decimal:4',
            'redeem_value_per_point' => 'decimal:4',
            'max_redeem_percent_of_subtotal' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
