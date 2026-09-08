<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines, with historical snapshots.
 *
 * WHY THE SNAPSHOTS EXIST
 * -----------------------
 * A line stores the product name, SKU, unit price, tax rate and unit cost *as
 * they were at the moment of sale*. If a Cappuccino sold for 2.50 and is
 * repriced to 3.00 tomorrow, this row still reads 2.50 - the receipt, the daily
 * takings and last month's margin all stay correct.
 *
 * These columns are not redundant copies of current data; they are the facts of
 * the transaction. Reading the price through product_id would make every past
 * report change whenever the menu changes, which is a correctness bug rather
 * than a normalisation improvement (denormalisation D1).
 *
 * product_id is still kept - it answers "how many Cappuccinos did we sell?",
 * which a name string cannot do reliably across renames.
 *
 * docs/05-database-design.md 5.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();

            // --- Historical snapshots (D1) ---
            $table->string('name_snapshot', 200);   // "Cappuccino (Large)"
            $table->string('sku_snapshot', 50);

            $table->decimal('quantity', 12, 3);     // Fractional sales: 0.5 kg of cake.
            $table->decimal('unit_price', 12, 2);   // Effective price at sale time.
            $table->decimal('line_subtotal', 12, 2);

            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);

            // Line discount PLUS this line's allocated share of any order-level
            // discount, so the line always footes to the order total.
            $table->decimal('line_discount_amount', 12, 2)->default(0);

            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_rate_snapshot', 6, 4)->default(0);
            $table->boolean('is_tax_inclusive')->default(false);
            $table->decimal('line_tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            // Cost at sale time, so margin is explicable years later even after
            // ingredient prices and recipes have moved on.
            $table->decimal('unit_cost_snapshot', 12, 4)->default(0);
            $table->decimal('line_cogs', 12, 4)->default(0);

            $table->string('status', 20)->default('pending');
            $table->string('note', 255)->nullable();   // "no ice", "extra hot"

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable();

            $table->timestamps();

            $table->foreign('order_id', 'fk_order_items_order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete()   // Lines are owned wholly by the order.
                ->cascadeOnUpdate();

            // RESTRICT: a product that has ever been sold cannot be hard-deleted,
            // which is what keeps product_id trustworthy for reporting.
            $table->foreign('product_id', 'fk_order_items_product_id')
                ->references('id')->on('products')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id', 'fk_order_items_product_variant_id')
                ->references('id')->on('product_variants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'fk_order_items_voided_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('order_id', 'idx_oi_order');
            $table->index('product_id', 'idx_oi_product');
            $table->index('product_variant_id', 'idx_oi_variant');
            $table->index('status', 'idx_oi_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
