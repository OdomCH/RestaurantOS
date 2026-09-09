<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notification table, plus two RestaurantOS columns.
 *
 * The framework structure is kept deliberately: it means Low Stock, New Order,
 * Order Ready, Payment Failure, Stock Transfer and System Alert are ordinary
 * Notification classes with mail/broadcast channels available for free, rather
 * than a bespoke table that reimplements all of it.
 *
 * Two additions:
 *  - `severity`, so an unread-critical badge does not have to deserialise every
 *    JSON payload;
 *  - `branch_id`, so a manager sees their own branch's alerts. Both are columns
 *    rather than keys inside `data` precisely because they are queried.
 *
 * docs/05-database-design.md 5.10, docs/19-notifications.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');

            $table->string('severity', 20)->default('info');
            $table->foreignId('branch_id')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id', 'fk_notifications_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_notifiable');
            $table->index(['branch_id', 'created_at'], 'idx_notifications_branch_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
