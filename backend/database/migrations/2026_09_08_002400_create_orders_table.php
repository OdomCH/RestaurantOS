<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central transactional table.
 *
 * An order belongs to a branch (required), a cashier (required) and a customer
 * (optional - most walk-in sales have none).
 *
 * The money columns duplicate sums over `order_items` - denormalisation D3. A
 * settled financial document must be immutable: recomputing totals from the
 * lines would let a later rounding-rule or tax change silently alter a receipt
 * that a customer already holds.
 *
 * docs/05-database-design.md 5.6, docs/11-order-workflow.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');

            // Human-readable, e.g. B1-20260905-0042. Allocated atomically from
            // `daily_sequences`, unique within its branch.
            $table->string('order_number', 30);

            // The branch business day, which is NOT derivable from created_at
            // without the branch timezone and its business_day_start cut-off.
            // Every sales report groups on this.
            $table->date('business_date');

            $table->string('order_type', 20)->default('dine_in');
            $table->string('table_number', 20)->nullable();

            $table->foreignId('customer_id')->nullable();
            $table->foreignId('user_id');
            $table->foreignId('cash_drawer_session_id')->nullable();

            $table->string('status', 20)->default('pending');

            // Derived from payments and refunds (FR-PAY-006). Never client-set.
            $table->string('payment_status', 20)->default('unpaid');

            // Snapshot of the branch currency: reinterpreting stored amounts
            // after a currency change would rewrite history.
            $table->char('currency_code', 3);

            $table->decimal('subtotal', 12, 2)->default(0);

            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->foreignId('discount_approved_by')->nullable();

            // Tracked apart from a manual discount so that reporting can tell a
            // giveaway from a customer spending their own points.
            $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            $table->decimal('loyalty_discount_amount', 12, 2)->default(0);

            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->decimal('service_charge_rate', 6, 4)->default(0);
            $table->decimal('service_charge_amount', 12, 2)->default(0);

            // Signed cash-rounding adjustment (docs/09-business-rules.md 8).
            $table->decimal('rounding_adjustment', 12, 2)->default(0);

            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->decimal('change_due', 12, 2)->default(0);

            // Materialised cost of goods sold, so the profit report does not
            // aggregate three million order_items rows.
            $table->decimal('cogs_total', 12, 4)->default(0);

            $table->string('note', 500)->nullable();

            $table->timestamp('placed_at');

            // Lifecycle stamps, denormalised from order_status_histories so that
            // SLA reporting is a column read rather than a self-join.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            // Idempotency guard for the recipe explosion: non-null means stock
            // has already been deducted, so a retry cannot double-deduct (C13).
            $table->timestamp('inventory_deducted_at')->nullable();

            // Optimistic locking - two terminals may edit the same open order.
            $table->unsignedInteger('version')->default(0);

            // Duplicate-submission guard for a flaky POS network.
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id', 'fk_orders_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // SET NULL, not CASCADE: erasing a customer must not delete their
            // orders, or the day's takings would change retrospectively.
            $table->foreign('customer_id', 'fk_orders_customer_id')
                ->references('id')->on('customers')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('user_id', 'fk_orders_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('cash_drawer_session_id', 'fk_orders_cash_drawer_session_id')
                ->references('id')->on('cash_drawer_sessions')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('discount_approved_by', 'fk_orders_discount_approved_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('cancelled_by', 'fk_orders_cancelled_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // Unique per branch, not globally: each branch runs its own daily
            // sequence and prefixes it with its own code.
            $table->unique(['branch_id', 'order_number'], 'uq_orders_branch_number');
            $table->unique(['idempotency_key', 'deleted_at'], 'uq_orders_idempotency');

            $table->index(['branch_id', 'business_date'], 'idx_orders_branch_business_date');
            $table->index(['branch_id', 'status'], 'idx_orders_branch_status');
            $table->index(['branch_id', 'payment_status'], 'idx_orders_branch_payment_status');
            $table->index('customer_id', 'idx_orders_customer');
            $table->index(['user_id', 'business_date'], 'idx_orders_user_business_date');
            $table->index('placed_at', 'idx_orders_placed_at');
            $table->index('deleted_at', 'idx_orders_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
