<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu categories, self-referencing to at most two levels (Drinks → Coffee).
 * Depth is capped in the application (C1): MySQL cannot express "depth ≤ 2".
 *
 * docs/05-database-design.md §5.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')->nullable();

            // Default routing for products in this category (FR-KDS-004).
            $table->foreignId('kitchen_station_id')->nullable();

            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('description', 500)->nullable();
            $table->string('image_path', 255)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id', 'fk_categories_parent_id')
                ->references('id')->on('categories')
                ->restrictOnDelete()  // Never orphan or silently delete a subtree.
                ->cascadeOnUpdate();

            $table->foreign('kitchen_station_id', 'fk_categories_kitchen_station_id')
                ->references('id')->on('kitchen_stations')
                ->nullOnDelete()      // Removing a station falls back to the default screen.
                ->cascadeOnUpdate();

            $table->unique(['slug', 'deleted_at'], 'uq_categories_slug');
            $table->index('parent_id', 'idx_categories_parent');
            $table->index(['is_active', 'sort_order'], 'idx_categories_active_sort');
            $table->index('deleted_at', 'idx_categories_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
