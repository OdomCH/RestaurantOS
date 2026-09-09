<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch cost, with an approval trail.
 *
 * `expense_date` is the date the cost belongs to, which is not the date it was
 * typed in - a receipt entered on the 3rd may belong to last month.
 *
 * The approver may never be the creator (C10). That is a policy check rather
 * than a constraint, because the approver is set by a later update.
 */
#[Fillable([
    'branch_id', 'expense_category_id', 'amount', 'tax_amount', 'total_amount',
    'payment_method', 'vendor_name', 'vendor_tax_id', 'description',
    'expense_date', 'receipt_path',
])]
class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'expense_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }
}
