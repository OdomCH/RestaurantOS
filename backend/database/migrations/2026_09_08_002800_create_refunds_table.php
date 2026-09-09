<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund is a separate record, never an edit of the payment.
 *
 * Reducing payments.amount would destroy the evidence that money was taken in
 * the first place and would silently change yesterday's takings. Two rows -
 * payment plus refund - keep both facts.
 *
 * docs/05-database-design.md 5.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id');
            $table->foreignId('order_id');    // Denormalised for order-level queries.
            $table->foreignId('branch_id');

            $table->string('refund_number', 30);
            $table->decimal('amount', 12, 2);

            // May differ from the original: a card sale refunded as cash.
            $table->string('method', 20);

            $table->string('reason', 255);    // Mandatory - never a silent refund.

            // Whether ingredients went back into stock. Usually false for food.
            $table->boolean('restock_inventory')->default(false);

            $table->foreignId('requested_by');
            $table->foreignId('approved_by')->nullable();

            $table->timestamp('refunded_at');
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamps();

            $table->foreign('payment_id', 'fk_refunds_payment_id')
                ->references('id')->on('payments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'fk_refunds_order_id')
                ->references('id')->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'fk_refunds_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('requested_by', 'fk_refunds_requested_by')
                ->references('id')->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('approved_by', 'fk_refunds_approved_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('refund_number', 'uq_refunds_number');
            $table->unique('idempotency_key', 'uq_refunds_idempotency');
            $table->index('payment_id', 'idx_refunds_payment');
            $table->index('order_id', 'idx_refunds_order');
            $table->index(['branch_id', 'refunded_at'], 'idx_refunds_branch_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
