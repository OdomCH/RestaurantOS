<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the framework `users` table rather than replacing it, so that the
 * default Laravel migration (which also creates `password_reset_tokens` and
 * `sessions`) stays untouched and Sanctum's own migration keeps working.
 *
 * This runs after `branches` because `users.branch_id` references it.
 *
 * **Why employment data lives here and not in a separate `employees` table:**
 * see docs/database/schema-overview.md §4. In short — every employee needs to
 * authenticate and every authenticating account is an employee, so the split
 * would be a strict one-to-one with no independent lifecycle.
 *
 * docs/05-database-design.md §5.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Home branch. NULL for Super Admin and Admin, who are not tied to
            // one location. Additional branch reach comes from `role_user`.
            $table->foreignId('branch_id')->nullable();

            // Employment attributes (STEP 6).
            $table->string('employee_code', 30)->nullable();
            $table->string('position', 80)->nullable();
            $table->date('hire_date')->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('avatar_path', 255)->nullable();

            // Hashed POS PIN (FR-AUTH-011). Never stored in plaintext, never
            // serialised, redacted in audit_logs.
            $table->string('pin_hash', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->softDeletes();

            $table->foreign('branch_id', 'fk_users_branch_id')
                ->references('id')->on('branches')
                ->nullOnDelete()      // Closing a branch must not delete its staff.
                ->cascadeOnUpdate();

            $table->unique('employee_code', 'uq_users_employee_code');
            $table->index('branch_id', 'idx_users_branch_id');
            $table->index('is_active', 'idx_users_is_active');
            $table->index('deleted_at', 'idx_users_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_branch_id');
            $table->dropUnique('uq_users_employee_code');
            $table->dropIndex('idx_users_branch_id');
            $table->dropIndex('idx_users_is_active');
            $table->dropIndex('idx_users_deleted_at');

            $table->dropColumn([
                'branch_id',
                'employee_code',
                'position',
                'hire_date',
                'phone',
                'avatar_path',
                'pin_hash',
                'is_active',
                'must_change_password',
                'last_login_at',
                'last_login_ip',
                'failed_login_attempts',
                'locked_until',
                'deleted_at',
            ]);
        });
    }
};
