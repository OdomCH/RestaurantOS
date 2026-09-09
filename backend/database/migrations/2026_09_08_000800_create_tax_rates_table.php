<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates are a table, not a column, because a rate changes over time and a
 * historic order must keep the rate that actually applied. The applied rate is
 * snapshotted onto `order_items.tax_rate_snapshot` at sale time.
 *
 * docs/05-database-design.md §5.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30);          // VAT_STD, VAT_RED, ZERO
            $table->string('name', 100);

            // Stored as a fraction: 0.0700 = 7 %. Never a percentage — that
            // convention removes a whole class of "divided by 100 twice" bugs.
            $table->decimal('rate', 6, 4);

            // Whether menu prices already include this tax. Changes the entire
            // line calculation, so it is snapshotted onto the order item too.
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_active')->default(true);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->unique('code', 'uq_tax_rates_code');
            $table->index(['is_active', 'effective_from', 'effective_to'], 'idx_tax_rates_active_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
