<?php

namespace App\Models;

use App\Enums\CashDrawerSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's shift at one drawer. At most one open session per user per branch,
 * guarded by a unique index on a generated column rather than by hope.
 */
#[Fillable(['opening_float', 'notes'])]
class CashDrawerSession extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CashDrawerSessionStatus::class,
            'business_date' => 'date',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** May differ from the owner when a manager closes an abandoned drawer. */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', CashDrawerSessionStatus::Open->value);
    }
}
