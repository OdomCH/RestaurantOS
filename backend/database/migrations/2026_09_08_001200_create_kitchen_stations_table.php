<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A station is one physical kitchen display: BAR, GRILL, PASTRY. Stations are
 * per branch — the grill at Phnom Penh is not the grill at Siem Reap.
 *
 * Created before `categories` because `categories.kitchen_station_id` routes
 * tickets to a station.
 *
 * docs/05-database-design.md §5.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->string('code', 30);
            $table->string('name', 100);

            // Late-ticket threshold. Snapshotted onto each ticket so that
            // changing the station's SLA does not re-grade yesterday's service.
            $table->unsignedSmallInteger('sla_minutes')->default(15);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('branch_id', 'fk_kitchen_stations_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['branch_id', 'code'], 'uq_stations_branch_code');
            $table->index(['branch_id', 'is_active'], 'idx_stations_branch_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_stations');
    }
};
