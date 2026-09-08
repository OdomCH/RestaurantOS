<?php

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The points ledger - append-only.
 *
 * Every earn, redemption, expiry, correction and reversal. `points` is signed;
 * `balance_after` lets a statement line be shown without summing a customer's
 * whole history. The cached balance on `customers` is checked against the sum of
 * these rows nightly.
 */
#[Fillable([
    'customer_id', 'order_id', 'type', 'points', 'balance_after',
    'monetary_value', 'reason', 'reverses_transaction_id', 'expires_at',
    'performed_by',
])]
class LoyaltyTransaction extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'type' => LoyaltyTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'monetary_value' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** NULL for a manual adjustment or an expiry. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The row this one undoes, when a refund claws points back. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
