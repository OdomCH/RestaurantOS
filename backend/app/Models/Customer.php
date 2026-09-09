<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A loyalty member. Global rather than per branch - being recognised everywhere
 * is the point of the programme.
 *
 * `loyalty_points_balance` is not fillable: points move only by writing a ledger
 * row, and the balance follows. Letting a request set it directly would make the
 * ledger and the balance disagree.
 */
#[Fillable([
    'code', 'first_name', 'last_name', 'phone', 'email',
    'address_line1', 'address_line2', 'city', 'postal_code',
    'birth_date', 'gender', 'notes', 'is_active',
])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'last_order_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'total_spent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function loyaltyTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The full points history - the authority behind the cached balance. */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /** Recomputes the balance from the ledger. Used by the nightly check (C7). */
    public function ledgerBalance(): int
    {
        return (int) $this->loyaltyTransactions()->sum('points');
    }
}
