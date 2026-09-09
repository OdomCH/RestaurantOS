<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rent, Electricity, Water, Transportation, Supplies, Maintenance, Other.
 *
 * A table rather than a string column on `expenses`, because a category carries
 * behaviour: whether it belongs in COGS rather than operating expense (which
 * changes the profit report) and whether it needs approval.
 *
 * docs/05-database-design.md 5.9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30);
            $table->string('name', 100);

            // Drives the profit report: a COGS-related cost sits above the
            // gross-margin line, an operating cost below it.
            $table->boolean('is_cogs_related')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('code', 'uq_expense_categories_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
