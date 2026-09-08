<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a sale, carrying its own history.
 *
 * The snapshot columns are the whole point of this model: `name_snapshot`,
 * `sku_snapshot`, `unit_price`, `tax_rate_snapshot` and `unit_cost_snapshot`
 * record what was true when the customer paid. Read the price from here, never
 * from the related product - the product's price is today's price, and this row
 * is last Tuesday's receipt.
 */
#[Fillable(['product_id', 'product_variant_id', 'quantity', 'note'])]
class OrderItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderItemStatus::class,
            'discount_type' => DiscountType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:4',
            'line_tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:4',
            'line_cogs' => 'decimal:4',
            'is_tax_inclusive' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Kept for reporting - what was sold, not what it cost. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function kitchenTicketItems(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }

    /** Voided lines keep their history but leave the totals. */
    public function scopeBillable($query)
    {
        return $query->where('status', '!=', OrderItemStatus::Voided->value);
    }

    public function margin(): float
    {
        return round((float) $this->line_total - (float) $this->line_cogs, 2);
    }
}
