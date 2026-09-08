<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A staff account. Authentication and employment data share this table
 * deliberately - see the extend_users_table migration for why there is no
 * separate `employees` model.
 *
 * `branch_id` is the home branch. Wider reach comes from `role_user.branch_id`,
 * so one person can hold different roles at different branches.
 *
 * Not fillable, on purpose: `is_active`, `pin_hash`, `failed_login_attempts`,
 * `locked_until`, `last_login_at` and `branch_id`. Each is set by a service that
 * also writes an audit entry; allowing them through mass assignment would let a
 * profile update quietly move someone to another branch (docs/22-security.md S7).
 */
#[Fillable([
    'name', 'email', 'password', 'phone', 'employee_code',
    'position', 'hire_date', 'avatar_path',
])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'hire_date' => 'date',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** Home branch. NULL for Super Admin and Admin. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Roles held, optionally scoped to a branch by the pivot.
     *
     * The pivot carries `branch_id`, so the same role row can be granted at
     * several branches and each grant is a separate assignment.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot(['branch_id', 'assigned_by', 'created_at']);
    }

    /** Orders rung up by this user. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function cashDrawerSessions(): HasMany
    {
        return $this->hasMany(CashDrawerSession::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'performed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Branch ids this user may act on: the home branch plus any branch-scoped
     * role grant. Authorisation itself is Day 3 - this is only the data.
     *
     * @return array<int, int>
     */
    public function accessibleBranchIds(): array
    {
        return collect([$this->branch_id])
            ->merge($this->roles->pluck('pivot.branch_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
