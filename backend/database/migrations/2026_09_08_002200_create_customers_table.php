<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers are global, not per branch: the point of a loyalty programme is that
 * a member is recognised at every location.
 *
 * The aggregate columns (`loyalty_points_balance`, `total_spent`, `total_orders`)
 * are denormalisation D4 — the ledger in `loyalty_transactions` remains the
 * system of record and ReconcileLoyaltyBalances checks them nightly (C7).
 *
 * docs/05-database-design.md §5.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);           // Membership number.
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();

            // The primary POS lookup key — a cashier types a phone number.
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();

            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();

            $table->foreignId('loyalty_tier_id')->nullable();

            // Denormalised balance (D4). Never decremented directly — only ever
            // recomputed from a ledger write.
            $table->integer('loyalty_points_balance')->default(0);

            // Monotonic: redeeming points must not demote a tier.
            $table->unsignedInteger('lifetime_points_earned')->default(0);

            $table->decimal('total_spent', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->timestamp('last_order_at')->nullable();

            $table->text('notes')->nullable();     // Allergies, preferences.
            $table->boolean('is_active')->default(true);

            // Right-to-erasure marker: personal fields are nulled while the
            // financial history behind orders.customer_id survives (S9).
            $table->timestamp('anonymised_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('loyalty_tier_id', 'fk_customers_loyalty_tier_id')
                ->references('id')->on('loyalty_tiers')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('code', 'uq_customers_code');
            $table->unique(['phone', 'deleted_at'], 'uq_customers_phone');
            $table->unique(['email', 'deleted_at'], 'uq_customers_email');
            $table->index('loyalty_tier_id', 'idx_customers_tier');
            $table->index('last_order_at', 'idx_customers_last_order');
            $table->index('deleted_at', 'idx_customers_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
