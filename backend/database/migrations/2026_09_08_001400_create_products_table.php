<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellable menu items.
 *
 * **Where the price lives.** `base_price` is on the product; a variant carries a
 * signed `price_delta` and a branch may carry a `price_override`. A menu-wide
 * price rise is then one UPDATE rather than one per variant per branch. The
 * effective price is resolved once, in docs/09-business-rules.md §3.
 *
 * docs/05-database-design.md §5.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id');
            // NULL means "use the branch default tax rate".
            $table->foreignId('tax_rate_id')->nullable();

            $table->string('sku', 50);
            $table->string('name', 150);
            $table->string('slug', 170);
            $table->text('description')->nullable();

            $table->decimal('base_price', 12, 2);

            // Manual cost for products without a recipe. Recipe-backed products
            // derive their cost from ingredients instead, so this stays NULL.
            $table->decimal('cost_price', 12, 4)->nullable();

            $table->string('image_path', 255)->nullable();

            $table->boolean('is_active')->default(true);   // Master switch, all branches.
            $table->boolean('is_available')->default(true); // Temporary "86'd" flag.
            $table->boolean('track_inventory')->default(true);
            $table->boolean('has_variants')->default(false);

            $table->unsignedSmallInteger('preparation_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id', 'fk_products_category_id')
                ->references('id')->on('categories')
                ->restrictOnDelete()  // A category with products cannot be deleted.
                ->cascadeOnUpdate();

            $table->foreign('tax_rate_id', 'fk_products_tax_rate_id')
                ->references('id')->on('tax_rates')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['sku', 'deleted_at'], 'uq_products_sku');
            $table->unique(['slug', 'deleted_at'], 'uq_products_slug');
            $table->index('category_id', 'idx_products_category');
            $table->index(['is_active', 'is_available'], 'idx_products_active_available');
            $table->index('deleted_at', 'idx_products_deleted_at');
        });

        // POS search (FR-POS-002). LIKE '%term%' cannot use an index; FULLTEXT
        // can. MySQL only — SQLite test runs fall back to a prefix LIKE.
        if (SchemaSupport::isMySql()) {
            Schema::table('products', function (Blueprint $table) {
                $table->fullText(['name', 'description'], 'ft_products_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
