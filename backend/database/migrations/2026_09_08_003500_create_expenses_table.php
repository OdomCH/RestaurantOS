<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch operating costs, with an approval trail.
 *
 * `expense_date` is separate from created_at because a receipt entered on the
 * 3rd may belong to the 28th of the previous month, and the profit report must
 * follow the business date rather than the typing date.
 *
 * Separation of duties: approved_by must differ from created_by (C10). MySQL
 * cannot express this as a CHECK reliably, because the approver is set by a
 * later UPDATE, so the ExpensePolicy enforces it and a test covers it.
 *
 * docs/05-database-design.md 5.9, docs/17-expense-management.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->foreignId('expense_category_id');

            $table->string('expense_number', 30);

            $table->decimal('amount', 12, 2);              // Net.
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);        // amount + tax_amount.
            $table->char('currency_code', 3);

            $table->string('payment_method', 20)->nullable();
            $table->string('vendor_name', 150)->nullable();
            $table->string('vendor_tax_id', 50)->nullable();
            $table->string('description', 500);

            $table->date('expense_date');
            $table->string('receipt_path', 255)->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by');
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->foreignId('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id', 'fk_expenses_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('expense_category_id', 'fk_expenses_expense_category_id')
                ->references('id')->on('expense_categories')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by', 'fk_expenses_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('approved_by', 'fk_expenses_approved_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('rejected_by', 'fk_expenses_rejected_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('paid_by', 'fk_expenses_paid_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('expense_number', 'uq_expenses_number');
            $table->index(['branch_id', 'expense_date'], 'idx_expenses_branch_date');
            $table->index('status', 'idx_expenses_status');
            $table->index('expense_category_id', 'idx_expenses_category');
            $table->index('created_by', 'idx_expenses_created_by');
            $table->index('deleted_at', 'idx_expenses_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
