<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of a recipe: Cappuccino ← Milk 0.20 L, Coffee 0.02 kg, Sugar 0.01 kg.
 *
 * This is the join that makes automatic deduction possible. When an order is
 * completed the exploder walks order_items → recipes → recipe_items, converts
 * each quantity into the ingredient's stock unit, multiplies by the line
 * quantity and the wastage factor, and writes one `sale_deduction` row per
 * ingredient into `stock_transactions` at the order's branch.
 *
 * docs/05-database-design.md §5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recipe_id');
            $table->foreignId('ingredient_id');

            // Per unit of yield, expressed in `unit_id` — which may differ from
            // the ingredient's stock unit and is converted at explosion.
            $table->decimal('quantity', 12, 4);
            $table->foreignId('unit_id');

            // Expected preparation loss, applied on deduction (FR-INV-012).
            $table->decimal('wastage_percent', 5, 2)->default(0);

            // An optional item does not block a sale when out of stock.
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->foreign('recipe_id', 'fk_recipe_items_recipe_id')
                ->references('id')->on('recipes')
                ->cascadeOnDelete()   // Lines are owned wholly by the recipe.
                ->cascadeOnUpdate();

            $table->foreign('ingredient_id', 'fk_recipe_items_ingredient_id')
                ->references('id')->on('ingredients')
                ->restrictOnDelete()  // An ingredient in use cannot be hard-deleted.
                ->cascadeOnUpdate();

            $table->foreign('unit_id', 'fk_recipe_items_unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // An ingredient may appear at most once per recipe (FR-INV-004);
            // two rows would make the effective quantity ambiguous.
            $table->unique(['recipe_id', 'ingredient_id'], 'uq_recipe_items');
            $table->index('ingredient_id', 'idx_recipe_items_ingredient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
