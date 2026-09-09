<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Granular permissions named `module.action`, e.g. `products.update`.
 *
 * The catalogue is data, not code: adding a permission is a seeder change, and
 * the role editor lists whatever the table holds.
 *
 * docs/05-database-design.md §5.2, docs/04-user-roles-permissions.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            $table->string('name', 80);
            // The module segment — the text before the dot. Kept as a column so
            // the role editor can group ~120 permissions without parsing names.
            $table->string('group', 40);
            $table->string('description', 255)->nullable();

            // Flags permissions that move money or hide evidence
            // (orders.void, payments.refund, inventory.adjust, users.impersonate)
            // for an extra UI confirmation and heavier audit treatment.
            $table->boolean('is_dangerous')->default(false);

            $table->timestamps();

            $table->unique('name', 'uq_permissions_name');
            $table->index('group', 'idx_permissions_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
