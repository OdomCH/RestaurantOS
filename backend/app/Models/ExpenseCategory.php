<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rent, Electricity, Water, Transportation, Supplies, Maintenance, Other.
 *
 * A table rather than a string, because the category decides where the cost
 * lands in the profit report and whether it needs approval.
 */
#[Fillable(['code', 'name', 'is_cogs_related', 'requires_approval', 'is_active'])]
class ExpenseCategory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_cogs_related' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
