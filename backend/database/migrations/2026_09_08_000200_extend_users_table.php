<?php

use App\Support\Database\SchemaSupport;
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
 * WHY AUTHENTICATION AND EMPLOYEE DATA SHARE ONE TABLE
 * ----------------------------------------------------
 * A separate `employees` table would be a strict one-to-one with `users`: every
 * employee needs to sign in, and every account belongs to an employee. A 1:1
 * split with no independent lifecycle buys nothing and costs a join on the hot
 * authentication path, plus a class of bugs where the two rows disagree.
 *
 * It would be the right call if the system had to track people who never log in
 * (kitchen porters on a rota) or keep employment history across rehiring. Neither
 * is in scope - see docs/database/schema-overview.md 4 for the full reasoning
 * and the migration path if that changes.
 *
 * `branch_id` stays here as the HOME branch. Wider reach is not a column: it is
 * `role_user.branch_id`, which lets one person be a Manager at one branch and a
 * Cashier at another without a second account.
 *
 * docs/05-database-design.md 5.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL for Super Admin and Admin, who are not tied to one location.
            $table->foreignId('branch_id')->nullable();

            // Employment attributes.
            $table->string('employee_code', 30)->nullable();
            $table->string('position', 80)->nullable();
            $table->date('hire_date')->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('avatar_path', 255)->nullable();

            // Hashed POS PIN (FR-AUTH-011). Never plaintext, never serialised,
            // redacted in audit_logs.
            $table->string('pin_hash', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();   // IPv6-capable.
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

    /**
     * MySQL only, by necessity (rule M1: an irreversible migration must say why).
     *
     * SQLite cannot drop a column that appears in a foreign key definition -
     * neither ALTER TABLE ... DROP COLUMN nor DROP FOREIGN KEY is available, and
     * PRAGMA foreign_keys = OFF does not change that. Rolling back would mean
     * rebuilding `users` by hand and copying the rows, which risks the one table
     * whose loss locks everyone out.
     *
     * This costs nothing in practice: the test suite uses RefreshDatabase, which
     * runs migrate:fresh rather than rollback, and every real environment is
     * MySQL (docs/05-database-design.md 1.1).
     */
    public function down(): void
    {
        if (! SchemaSupport::isMySql()) {
            throw new RuntimeException(
                'extend_users_table can only be rolled back on MySQL: SQLite cannot drop '.
                'a column referenced by a foreign key. Use migrate:fresh instead.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            // The constraint has to go before its column can.
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
