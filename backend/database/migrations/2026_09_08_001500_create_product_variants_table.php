<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Size and option variants: Cappuccino → Small / Medium / Large.
 *
 * A variant stores a **signed delta**, not an absolute price. Repricing the
 * menu then touches one `products.base_price` instead of every variant row. The
 * trade-off — a variant cannot be priced entirely independently of its product —
 * matches how restaurant menus are actually repriced.
 *
 * docs/05-database-design.md §5.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id');

            $table->string('sku', 50);
            $table->string('name', 100);          // Small, Medium, Large

            // Signed: a smaller size may be negative.
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('cost_delta', 12, 4)->default(0);

            // Exactly one default per product — enforced in the application
            // (C4), because MySQL has no partial unique index.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id', 'fk_product_variants_product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete()   // A variant has no meaning without its product.
                ->cascadeOnUpdate();

            $table->unique(['sku', 'deleted_at'], 'uq_variants_sku');
            $table->unique(['product_id', 'name', 'deleted_at'], 'uq_variants_product_name');
            $table->index('product_id', 'idx_variants_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
