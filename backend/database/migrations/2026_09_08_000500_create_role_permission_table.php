<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many: roles ↔ permissions.
 *
 * No surrogate key and no timestamps — the grant either exists or it does not,
 * and `audit_logs` records who changed it and when.
 *
 * docs/05-database-design.md §5.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');

            // Composite natural primary key: a permission cannot be granted to
            // the same role twice.
            $table->primary(['role_id', 'permission_id']);

            $table->foreign('role_id', 'fk_role_permission_role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete()   // Deleting a role removes its grants; nothing else references them.
                ->cascadeOnUpdate();

            $table->foreign('permission_id', 'fk_role_permission_permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Reverse lookup: "which roles hold this permission?"
            $table->index('permission_id', 'idx_rp_permission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
