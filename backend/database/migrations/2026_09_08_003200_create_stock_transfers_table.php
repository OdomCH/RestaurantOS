<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving stock between branches: Requested -> Approved -> In Transit -> Received.
 *
 * Two branch keys on one row make this the schema's clearest example of why
 * `ON DELETE CASCADE` everywhere would be dangerous: cascading from a branch
 * would delete transfers that the OTHER branch still needs for its own stock
 * history. Both keys are RESTRICT.
 *
 * A branch may not transfer to itself - enforced by chk_stock_transfers_branches.
 *
 * docs/05-database-design.md 5.8, docs/15-stock-transfer.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_number', 30);

            $table->foreignId('from_branch_id');
            $table->foreignId('to_branch_id');

            $table->string('status', 20)->default('draft');

            $table->foreignId('requested_by');
            $table->foreignId('approved_by')->nullable();
            $table->foreignId('dispatched_by')->nullable();
            $table->foreignId('received_by')->nullable();
            $table->foreignId('cancelled_by')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expected_arrival_at')->nullable();

            // Valuation moved, so the receiving branch can cost what it got.
            $table->decimal('total_cost', 14, 4)->default(0);

            // Set when received quantities differ from dispatched (FR-TRF-005).
            $table->boolean('has_variance')->default(false);

            $table->string('note', 500)->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('from_branch_id', 'fk_stock_transfers_from_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('to_branch_id', 'fk_stock_transfers_to_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('requested_by', 'fk_stock_transfers_requested_by')
                ->references('id')->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('approved_by', 'fk_stock_transfers_approved_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('dispatched_by', 'fk_stock_transfers_dispatched_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('received_by', 'fk_stock_transfers_received_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('cancelled_by', 'fk_stock_transfers_cancelled_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('transfer_number', 'uq_transfers_number');

            // Two indexes, not one: "transfers involving my branch" is an OR
            // across two columns and cannot use a single composite index.
            $table->index(['from_branch_id', 'status'], 'idx_transfers_from_status');
            $table->index(['to_branch_id', 'status'], 'idx_transfers_to_status');
            $table->index(['status', 'created_at'], 'idx_transfers_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
