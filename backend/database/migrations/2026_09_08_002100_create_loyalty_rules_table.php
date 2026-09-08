<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Earn and redeem rates are configuration, never constants in code: "$1 = 1
 * point" is this table's default row, not a hard-coded literal.
 *
 * Exactly one rule is active at a time (C3).
 *
 * docs/05-database-design.md §5.5, docs/16-customer-loyalty.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // Points granted per 1.00 of currency spent.
            $table->decimal('earn_points_per_currency_unit', 10, 4);

            // Which figure earns: subtotal, net_of_discount or grand_total.
            // Earning on grand_total pays points on tax and service charge,
            // which most operators do not intend — hence an explicit choice.
            $table->string('earn_basis', 20)->default('net_of_discount');

            // Currency value of one point when redeemed.
            $table->decimal('redeem_value_per_point', 10, 4);

            $table->unsignedInteger('min_points_to_redeem')->default(0);
            $table->unsignedInteger('redeem_increment')->default(1);

            // Cap on how much of an order points may pay for. NULL = uncapped.
            $table->decimal('max_redeem_percent_of_subtotal', 5, 2)->nullable();

            // NULL = points never expire.
            $table->unsignedSmallInteger('points_expiry_days')->nullable();

            $table->boolean('is_active')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'effective_from', 'effective_to'], 'idx_loyalty_rules_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};
