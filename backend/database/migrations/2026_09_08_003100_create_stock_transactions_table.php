<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE INVENTORY SYSTEM OF RECORD - append-only.
 *
 * Every movement of every ingredient at every branch is one row here: purchases,
 * wastage, corrections, transfers in and out, and the automatic deduction that
 * follows a sale. `inventories.quantity_on_hand` is only a cached sum of this
 * ledger and can be rebuilt from it at any time.
 *
 * BRANCH ISOLATION: branch_id is NOT NULL and leads the main index. There is no
 * row here that belongs to "the company" rather than to a branch, so no query
 * can spend Siem Reap's milk in Phnom Penh.
 *
 * IMMUTABILITY: no UPDATE, no DELETE, ever. A mistake is corrected by inserting
 * a compensating row, which preserves both the error and the fix. Enforced at
 * three levels - no code path, no grant, and a trigger (see migration 004000).
 *
 * docs/05-database-design.md 5.8, docs/14-inventory-workflow.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->foreignId('ingredient_id');

            $table->string('type', 30);

            // SIGNED: negative is consumption. Never zero - a zero movement is
            // a bug, not a fact (enforced by chk_stock_transactions_nonzero).
            $table->decimal('quantity_change', 14, 4);

            // Always the ingredient stock unit, after conversion.
            $table->foreignId('unit_id');

            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);

            // Running balance and average cost after this row. Makes any
            // point-in-time balance readable without summing the whole history.
            $table->decimal('balance_after', 14, 4);
            $table->decimal('average_cost_after', 12, 4)->default(0);

            // Polymorphic source: Order, StockTransfer, Refund, or NULL for a
            // manual movement.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Mandatory for adjustment, wastage and count_correction.
            $table->string('reason', 255)->nullable();

            // NULL for system-generated rows (a sale deduction has no operator).
            $table->foreignId('performed_by')->nullable();

            // Business time, which may differ from created_at for a backdated
            // delivery entered the next morning.
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('branch_id', 'fk_stock_transactions_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('ingredient_id', 'fk_stock_transactions_ingredient_id')
                ->references('id')->on('ingredients')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('unit_id', 'fk_stock_transactions_unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('performed_by', 'fk_stock_transactions_performed_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // The primary access path: movement report and balance rebuild.
            $table->index(['branch_id', 'ingredient_id', 'occurred_at'], 'idx_st_branch_ingredient_occurred');
            // "What did this order consume?"
            $table->index(['reference_type', 'reference_id'], 'idx_st_reference');
            $table->index(['type', 'occurred_at'], 'idx_st_type_occurred');
            $table->index('occurred_at', 'idx_st_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
