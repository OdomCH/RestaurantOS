<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The materialised stock balance: exactly one row per (branch, ingredient).
 *
 * **This is where branch isolation is enforced.** Milk at Phnom Penh and milk at
 * Siem Reap are two rows, never one. There is no global stock figure anywhere in
 * the schema, so no query can accidentally spend another branch's stock.
 *
 * The balance duplicates SUM(stock_transactions.quantity_change) — denormalisation
 * D2. Summing eight million ledger rows on every POS keystroke is not viable, so
 * the ledger stays the system of record and this row is the fast read, reconciled
 * nightly by ReconcileInventoryBalances (C8).
 *
 * docs/05-database-design.md §5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->foreignId('ingredient_id');

            // Always in the ingredient's stock unit.
            $table->decimal('quantity_on_hand', 14, 4)->default(0);

            // Post-MVP reservation. The column exists now so that the
            // availability formula never has to change later.
            $table->decimal('reserved_quantity', 14, 4)->default(0);

            // Dispatched by another branch, not yet received here. Tracked at the
            // destination and available nowhere until receipt (FR-TRF-006).
            $table->decimal('in_transit_quantity', 14, 4)->default(0);

            // Weighted average cost, recalculated on every inbound movement.
            $table->decimal('average_cost', 12, 4)->default(0);

            $table->timestamp('last_movement_at')->nullable();
            $table->timestamp('last_counted_at')->nullable();

            // Notification de-duplication: without it a low-stock alert fires on
            // every sale once the balance dips (FR-NTF-004).
            $table->timestamp('low_stock_notified_at')->nullable();

            $table->timestamps();

            $table->foreign('branch_id', 'fk_inventories_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('ingredient_id', 'fk_inventories_ingredient_id')
                ->references('id')->on('ingredients')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Also the point-lookup index used under FOR UPDATE during a sale.
            $table->unique(['branch_id', 'ingredient_id'], 'uq_inventories_branch_ingredient');
            $table->index('ingredient_id', 'idx_inventories_ingredient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
