<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer lines.
 *
 * Four quantity columns rather than one, because each stage is a separate fact:
 * 10 kg requested, 8 kg approved, 8 kg dispatched, 7.5 kg received. Collapsing
 * them would hide shrinkage in transit - which is exactly what a manager needs
 * to see.
 *
 * docs/05-database-design.md 5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_transfer_id');
            $table->foreignId('ingredient_id');
            $table->foreignId('unit_id');

            $table->decimal('requested_quantity', 14, 4);
            $table->decimal('approved_quantity', 14, 4)->nullable();
            $table->decimal('dispatched_quantity', 14, 4)->nullable();
            $table->decimal('received_quantity', 14, 4)->nullable();

            // Signed: received - dispatched.
            $table->decimal('variance_quantity', 14, 4)->nullable();

            // Source branch average cost at dispatch, so the destination values
            // the stock at what it actually cost, not at today's price.
            $table->decimal('unit_cost', 12, 4)->nullable();

            $table->string('variance_reason', 255)->nullable();

            $table->timestamps();

            $table->foreign('stock_transfer_id', 'fk_stock_transfer_items_transfer_id')
                ->references('id')->on('stock_transfers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('ingredient_id', 'fk_stock_transfer_items_ingredient_id')
                ->references('id')->on('ingredients')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('unit_id', 'fk_stock_transfer_items_unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['stock_transfer_id', 'ingredient_id'], 'uq_sti_transfer_ingredient');
            $table->index('ingredient_id', 'idx_sti_ingredient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
