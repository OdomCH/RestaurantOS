<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stock moving between branches: requested, approved, dispatched, received.
 *
 * Dispatch writes a transfer_out at the source; receipt writes a transfer_in at
 * the destination. Between the two the stock is in_transit and available
 * nowhere, which is the honest answer to "where is it?".
 */
#[Fillable(['from_branch_id', 'to_branch_id', 'note', 'expected_arrival_at'])]
class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'total_cost' => 'decimal:4',
            'has_variance' => 'boolean',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expected_arrival_at' => 'datetime',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Transfers this branch is involved in, either end. */
    public function scopeInvolvingBranch($query, int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->where('from_branch_id', $branchId)
                ->orWhere('to_branch_id', $branchId);
        });
    }
}
