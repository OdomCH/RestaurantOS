<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A restaurant location. The root of almost every scope in the system: orders,
 * stock, staff, expenses and kitchen screens all belong to exactly one branch.
 */
#[Fillable([
    'code', 'name', 'address_line1', 'address_line2', 'city', 'state',
    'postal_code', 'country_code', 'phone', 'email', 'timezone',
    'currency_code', 'business_day_start', 'opening_time', 'closing_time',
    'tax_registration_number', 'receipt_footer', 'is_active',
])]
class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function kitchenStations(): HasMany
    {
        return $this->hasMany(KitchenStation::class);
    }

    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    public function cashDrawerSessions(): HasMany
    {
        return $this->hasMany(CashDrawerSession::class);
    }

    /** Branch-specific stock balances - never shared between branches. */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    /** Transfers this branch is sending out. */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_branch_id');
    }

    /** Transfers arriving here. */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_branch_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function dailySequences(): HasMany
    {
        return $this->hasMany(DailySequence::class);
    }

    /** Products this branch overrides - price, or availability. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'branch_product')
            ->withPivot(['price_override', 'is_available'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
