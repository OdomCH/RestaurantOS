<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many: users ↔ roles, optionally scoped to one branch.
 *
 * The `branch_id` on the pivot is what makes multi-branch RBAC work: the same
 * person can be a Manager at Phnom Penh and a Cashier at Siem Reap without two
 * accounts.
 *
 * docs/05-database-design.md §5.2, docs/04-user-roles-permissions.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id');
            $table->foreignId('role_id');

            // NULL = the role applies at the user's home branch / full reach.
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('assigned_by')->nullable();

            $table->timestamp('created_at')->nullable();

            // MySQL treats every NULL in a unique index as distinct, so
            // UNIQUE (user_id, role_id, branch_id) would happily accept the same
            // global assignment twice. Folding NULL to 0 in a stored generated
            // column restores the intended constraint.
            $table->unsignedBigInteger('branch_key')
                ->storedAs(SchemaSupport::nullSafe('branch_id'));

            $table->foreign('user_id', 'fk_role_user_user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('role_id', 'fk_role_user_role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'fk_role_user_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()   // A deleted branch's scoped assignments are meaningless.
                ->cascadeOnUpdate();

            $table->foreign('assigned_by', 'fk_role_user_assigned_by')
                ->references('id')->on('users')
                ->nullOnDelete()      // Keep the assignment when the granter leaves.
                ->cascadeOnUpdate();

            $table->unique(['user_id', 'role_id', 'branch_key'], 'uq_role_user');
            $table->index('role_id', 'idx_role_user_role');
            $table->index('branch_id', 'idx_role_user_branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
