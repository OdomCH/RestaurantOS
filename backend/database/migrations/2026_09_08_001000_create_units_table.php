<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure — a table rather than an enum on `ingredients`, because
 * conversion needs a factor and a family. A recipe may call for 200 ml while
 * stock is kept in litres; the exploder converts using `conversion_factor`.
 *
 * Self-referencing: `base_unit_id IS NULL` marks the base unit of a family.
 *
 * docs/05-database-design.md §5.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();

            $table->string('code', 10);           // kg, g, l, ml, pcs
            $table->string('name', 50);
            $table->string('family', 20);         // mass, volume, count

            // NULL for the family's own base unit (kg, l, pcs).
            $table->foreignId('base_unit_id')->nullable();

            // Multiplier to the base unit: g → kg is 0.001.
            $table->decimal('conversion_factor', 16, 6)->default(1);
            $table->unsignedTinyInteger('precision_digits')->default(3);

            $table->timestamps();

            $table->foreign('base_unit_id', 'fk_units_base_unit_id')
                ->references('id')->on('units')
                ->restrictOnDelete()  // Deleting kg while g points at it would orphan the family.
                ->cascadeOnUpdate();

            $table->unique('code', 'uq_units_code');
            $table->index('family', 'idx_units_family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
