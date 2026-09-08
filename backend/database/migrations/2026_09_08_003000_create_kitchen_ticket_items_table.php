<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which order lines belong to which station ticket.
 *
 * Deliberately thin: it points at order_items rather than copying names,
 * quantities or notes. The kitchen reads "2 x Cappuccino (Large), no ice" by
 * joining, so a corrected line is corrected everywhere at once.
 *
 * docs/05-database-design.md 5.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kitchen_ticket_id');
            $table->foreignId('order_item_id');

            $table->string('status', 20)->default('queued');
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('prepared_by')->nullable();

            $table->timestamps();

            $table->foreign('kitchen_ticket_id', 'fk_kitchen_ticket_items_ticket_id')
                ->references('id')->on('kitchen_tickets')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_item_id', 'fk_kitchen_ticket_items_order_item_id')
                ->references('id')->on('order_items')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('prepared_by', 'fk_kitchen_ticket_items_prepared_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['kitchen_ticket_id', 'order_item_id'], 'uq_kti_ticket_item');
            $table->index('order_item_id', 'idx_kti_order_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
    }
};
