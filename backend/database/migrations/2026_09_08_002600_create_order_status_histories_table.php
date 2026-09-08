<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only transition log for the order state machine.
 *
 * `orders` carries accepted_at / ready_at / completed_at for fast reporting, but
 * those five columns cannot record who moved the order, why it was cancelled, or
 * that a ticket was recalled from Ready back to Preparing. This table can, and
 * it is the evidence behind every SLA figure.
 *
 * No updated_at, no deleted_at: a history row is never edited or removed.
 *
 * docs/05-database-design.md 5.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');

            $table->string('from_status', 20)->nullable();  // NULL on the first row.
            $table->string('to_status', 20);

            // NULL for system transitions (auto-complete, scheduled cancel).
            $table->foreignId('changed_by')->nullable();

            $table->string('reason', 255)->nullable();      // Mandatory on cancel.

            // Seconds spent in from_status. Precomputed so SLA reports do not
            // self-join a four-million-row table.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id', 'fk_order_status_histories_order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('changed_by', 'fk_order_status_histories_changed_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['order_id', 'created_at'], 'idx_osh_order_created');
            $table->index('to_status', 'idx_osh_to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
