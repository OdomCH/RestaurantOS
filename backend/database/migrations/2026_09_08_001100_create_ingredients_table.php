<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredients are global master data (Milk, Coffee Beans, Sugar). The *stock*
 * of an ingredient is branch-specific and lives in `inventories` and
 * `stock_transactions` — never here.
 *
 * docs/05-database-design.md §5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->nullable();
            $table->string('name', 120);

            // The stock-keeping unit: balances are always held in this unit,
            // whatever unit a recipe or a delivery note happens to use.
            $table->foreignId('unit_id');

            // Free-form reporting grouping (dairy, dry goods, produce). Not a
            // table: nothing joins on it and it carries no behaviour.
            $table->string('category', 60)->nullable();

            // Fallback cost used when a branch has no movement history yet.
            $table->decimal('default_cost_per_unit', 12, 4)->default(0);

            $table->decimal('reorder_level', 14, 4)->default(0);
            $table->decimal('reorder_quantity', 14, 4)->nullable();

            $table->boolean('is_perishable')->default(false);
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('unit_id', 'fk_ingredients_unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Reusable after deletion: including `deleted_at` lets a retired
            // ingredient's name and code be used again, because MySQL treats
            // NULLs in a unique index as distinct (§1.5).
            $table->unique(['name', 'deleted_at'], 'uq_ingredients_name');
            $table->unique(['code', 'deleted_at'], 'uq_ingredients_code');
            $table->index('is_active', 'idx_ingredients_active');
            $table->index('unit_id', 'idx_ingredients_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
