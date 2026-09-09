<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/05-database-design.md §5.2, docs/04-user-roles-permissions.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 50);            // Slug: super_admin, cashier, …
            $table->string('display_name', 100);
            $table->string('description', 255)->nullable();

            // Escalation hierarchy (04 §6.1): a user may never grant a role
            // whose rank is greater than or equal to their own highest rank.
            $table->unsignedSmallInteger('rank');

            // System roles cannot be renamed or deleted by an administrator;
            // the application depends on their slugs.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique('name', 'uq_roles_name');
            $table->index('rank', 'idx_roles_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
