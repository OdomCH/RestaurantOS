<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe binds a product (optionally a specific variant) to the ingredients it
 * consumes. Selling the product then deducts stock automatically — see
 * `recipe_items` and docs/14-inventory-workflow.md §4.
 *
 * **Versioned, never edited in place once used.** Changing a live recipe would
 * silently change the COGS of products already costed, making historic margin
 * inexplicable. A change creates version n+1 and deactivates version n.
 *
 * docs/05-database-design.md §5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');

            // NULL = applies to every variant. A recipe naming a specific
            // variant wins over the generic one at explosion time.
            $table->foreignId('product_variant_id')->nullable();

            $table->unsignedSmallInteger('version')->default(1);

            // Units produced per execution: a 1-litre sauce batch that serves 8
            // has yield 8, so ingredient quantities divide correctly.
            $table->decimal('yield_quantity', 12, 3)->default(1);

            // Exactly one active version per (product, variant) — C2.
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id', 'fk_recipes_product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id', 'fk_recipes_product_variant_id')
                ->references('id')->on('product_variants')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(
                ['product_id', 'product_variant_id', 'version', 'deleted_at'],
                'uq_recipes_product_variant_version'
            );
            $table->index(['product_id', 'is_active'], 'idx_recipes_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
