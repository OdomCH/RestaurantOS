<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless, concurrency-safe document numbering per branch, per scope, per day.
 *
 * `SELECT MAX(order_number) + 1` is a race: two cashiers ringing up at the same
 * moment produce the same number. Allocation here is a single atomic statement —
 * `INSERT ... ON DUPLICATE KEY UPDATE last_number = last_number + 1` — whose
 * uniqueness is guaranteed by `uq_daily_sequences`.
 *
 * docs/05-database-design.md §5.3, docs/02-system-architecture.md §7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->string('scope', 20);          // order, expense, transfer, refund, payment
            $table->date('sequence_date');        // Branch business day, not UTC date.
            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            $table->foreign('branch_id', 'fk_daily_sequences_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['branch_id', 'scope', 'sequence_date'], 'uq_daily_sequences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sequences');
    }
};
