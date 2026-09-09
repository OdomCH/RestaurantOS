<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch availability and price override (FR-CAT-005).
 *
 * The menu is global; what a given branch sells, and for how much, is not. A
 * missing row means "inherit the global product" — the table only records
 * deviations, which keeps it small.
 *
 * docs/05-database-design.md §5.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_product', function (Blueprint $table) {
            $table->foreignId('branch_id');
            $table->foreignId('product_id');

            // NULL = use products.base_price.
            $table->decimal('price_override', 12, 2)->nullable();
            $table->boolean('is_available')->default(true);

            $table->timestamps();

            $table->primary(['branch_id', 'product_id']);

            $table->foreign('branch_id', 'fk_branch_product_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_id', 'fk_branch_product_product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->index('product_id', 'idx_bp_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product');
    }
};
