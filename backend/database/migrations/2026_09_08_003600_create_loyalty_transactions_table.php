<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE LOYALTY LEDGER - append-only.
 *
 * A single mutable `points` column on `customers` would be indefensible: when a
 * customer says "I had 400 points last week", a bare number cannot answer, and a
 * bug that decrements twice is undetectable and unrecoverable. Every earn,
 * redemption, expiry, correction and reversal is a row here; the balance on
 * `customers` is a cache of SUM(points), checked nightly (C7).
 *
 * Runs after `orders` because an earn row references the order that produced it.
 *
 * docs/05-database-design.md 5.5, docs/16-customer-loyalty.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id');

            // NULL for a manual adjustment or an expiry, which have no order.
            $table->foreignId('order_id')->nullable();

            $table->string('type', 30);

            // SIGNED: positive earns, negative redeems and expiries. The sign is
            // the source of truth; `type` explains why.
            $table->integer('points');

            // Running balance, so a statement line can be shown without summing
            // the customer's whole history.
            $table->integer('balance_after');

            // Currency value of a redemption, for reporting the cost of the
            // programme.
            $table->decimal('monetary_value', 12, 2)->nullable();

            $table->string('reason', 255)->nullable();   // Mandatory for adjust.

            // Links a reversal to the row it undoes, so a refunded order's
            // clawback is traceable to the earn it cancels.
            $table->foreignId('reverses_transaction_id')->nullable();

            // Set on earn rows when the active rule configures expiry.
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('performed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_id', 'fk_loyalty_transactions_customer_id')
                ->references('id')->on('customers')
                ->restrictOnDelete()  // Never lose a ledger by deleting its owner.
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'fk_loyalty_transactions_order_id')
                ->references('id')->on('orders')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reverses_transaction_id', 'fk_loyalty_transactions_reverses')
                ->references('id')->on('loyalty_transactions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('performed_by', 'fk_loyalty_transactions_performed_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['customer_id', 'created_at'], 'idx_lt_customer_created');
            $table->index('order_id', 'idx_lt_order');
            $table->index(['type', 'expires_at'], 'idx_lt_type_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
