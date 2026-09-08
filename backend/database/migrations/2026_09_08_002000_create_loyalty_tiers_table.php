<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership tiers (Bronze, Silver, Gold). Promotion is driven by
 * `customers.lifetime_points_earned`, which never decreases — spending points
 * must not demote a customer.
 *
 * docs/05-database-design.md §5.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 50);
            $table->unsignedInteger('min_lifetime_points');

            // Multiplies the base earn rate for this tier.
            $table->decimal('earn_rate_multiplier', 6, 3)->default(1);

            // Automatic discount granted by tier. NULL = none.
            $table->decimal('discount_percent', 5, 2)->nullable();

            // Display-only marketing copy. JSON is acceptable here precisely
            // because nothing queries or joins on it.
            $table->json('benefits')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('name', 'uq_loyalty_tiers_name');
            $table->unique('min_lifetime_points', 'uq_loyalty_tiers_min_points');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
