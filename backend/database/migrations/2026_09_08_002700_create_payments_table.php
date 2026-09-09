<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tender. An order may have several: 20.00 cash plus 14.44 card is
 * two rows, which is why payments is a separate table and not columns on orders.
 *
 * DUPLICATE PROTECTION
 * --------------------
 * A POS on a flaky network retries. Three defences, in order of strength:
 *   1. `uq_payments_idempotency` - the same client-generated key can insert once.
 *   2. `uq_payments_number` - the sequence allocator cannot mint a number twice.
 *   3. The service re-reads paid_total inside the transaction and refuses to
 *      over-collect (C6).
 * Only the first two are database-level, and they are the ones that hold when a
 * background job or a console command bypasses the service layer.
 *
 * docs/05-database-design.md 5.6, docs/12-payment-workflow.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');

            // Denormalised from the order so that branch-scoped payment and
            // shift queries need no join. Kept consistent by the service.
            $table->foreignId('branch_id');

            $table->string('payment_number', 30);
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);          // Applied to the order.

            // Cash only: what the customer handed over and what came back.
            $table->decimal('tendered_amount', 12, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->nullable();

            $table->char('currency_code', 3);
            $table->string('status', 20)->default('captured');

            // Approval code, QR reference, transfer slip. Mandatory for
            // non-cash methods (FR-PAY-005), enforced in the request class.
            $table->string('reference', 100)->nullable();

            // The ONLY card data ever stored. No PAN, no CVV, no track data.
            $table->char('card_last_four', 4)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('provider', 50)->nullable();

            $table->foreignId('received_by');
            $table->foreignId('cash_drawer_session_id')->nullable();

            $table->timestamp('paid_at');

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamps();

            // RESTRICT: an order carrying money must never be hard-deleted.
            $table->foreign('order_id', 'fk_payments_order_id')
                ->references('id')->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'fk_payments_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('received_by', 'fk_payments_received_by')
                ->references('id')->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('cash_drawer_session_id', 'fk_payments_cash_drawer_session_id')
                ->references('id')->on('cash_drawer_sessions')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'fk_payments_voided_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('payment_number', 'uq_payments_number');
            $table->unique('idempotency_key', 'uq_payments_idempotency');
            $table->index('order_id', 'idx_payments_order');
            $table->index(['branch_id', 'paid_at'], 'idx_payments_branch_paid_at');
            $table->index(['method', 'status'], 'idx_payments_method_status');
            $table->index('cash_drawer_session_id', 'idx_payments_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
