<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY THE KITCHEN GETS ITS OWN TABLES
 * -----------------------------------
 * A ticket is NOT a copy of the order. It exists because one order routes to
 * several stations: the barista sees the drinks, the grill sees the food, and
 * each screen completes its own work at its own pace. That is a genuine
 * one-order-to-many-tickets relationship which order rows cannot express -
 * `orders.status` has exactly one value, and "drinks ready, food still cooking"
 * has two.
 *
 * No order data is duplicated: a ticket holds routing, timing and its own
 * status, and joins back to order_items for what to actually make.
 *
 * The order-level workflow (Pending, Accepted, Preparing, Ready, Completed)
 * stays on `orders`; the ticket status is the per-station view of it.
 *
 * docs/05-database-design.md 5.7, docs/13-kitchen-workflow.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');
            $table->foreignId('branch_id');

            // NULL routes to the branch default screen.
            $table->foreignId('kitchen_station_id')->nullable();

            $table->string('ticket_number', 30);
            $table->string('status', 20)->default('queued');

            // Higher jumps the queue: allergy orders, a delayed table.
            $table->unsignedTinyInteger('priority')->default(0);

            // Snapshot from the station, so changing the SLA today does not
            // re-grade yesterday's service.
            $table->unsignedSmallInteger('sla_minutes')->default(15);

            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();

            $table->foreignId('prepared_by')->nullable();

            // Two screens may act on one ticket at the same moment.
            $table->unsignedInteger('version')->default(0);

            $table->timestamps();

            $table->foreign('order_id', 'fk_kitchen_tickets_order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'fk_kitchen_tickets_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('kitchen_station_id', 'fk_kitchen_tickets_kitchen_station_id')
                ->references('id')->on('kitchen_stations')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('prepared_by', 'fk_kitchen_tickets_prepared_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // One ticket per order per station.
            $table->unique(['order_id', 'kitchen_station_id'], 'uq_kt_order_station');

            // The KDS poll - the highest-frequency query in the system.
            $table->index(['branch_id', 'status', 'queued_at'], 'idx_kt_branch_status_queued');
            $table->index(['kitchen_station_id', 'status'], 'idx_kt_station_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};
