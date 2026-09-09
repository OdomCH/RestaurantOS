<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the append-only tables append-only in fact, not merely by convention.
 *
 * WHY A TRIGGER AND NOT JUST DISCIPLINE
 * -------------------------------------
 * `stock_transactions`, `loyalty_transactions`, `audit_logs` and
 * `order_status_histories` are evidence. If a balance can be quietly edited, the
 * ledger stops being able to explain the balance, and an audit log that can be
 * rewritten proves nothing about anything.
 *
 * Defence in depth, three layers (docs/22-security.md S4/S5):
 *   1. No application code path issues UPDATE or DELETE on these tables.
 *   2. The application database user is granted only SELECT and INSERT on them
 *      (deployment concern - docs/26-deployment.md).
 *   3. These triggers, which hold even against a direct console session using
 *      the migration user.
 *
 * Corrections are made by INSERTing a compensating row, which preserves both the
 * mistake and the fix.
 *
 * MySQL only. The rule is asserted in tests that run against MySQL; on the
 * SQLite test connection the first layer alone applies.
 */
return new class extends Migration
{
    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        return [
            'stock_transactions',
            'loyalty_transactions',
            'audit_logs',
            'order_status_histories',
        ];
    }

    public function up(): void
    {
        if (! SchemaSupport::isMySql()) {
            return;
        }

        foreach ($this->tables() as $table) {
            foreach (['update', 'delete'] as $event) {
                $trigger = "trg_{$table}_no_{$event}";
                $message = "{$table} is append-only: write a compensating row instead.";

                DB::unprepared(
                    "CREATE TRIGGER `{$trigger}` BEFORE ".strtoupper($event)." ON `{$table}` ".
                    "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
                );
            }
        }
    }

    public function down(): void
    {
        if (! SchemaSupport::isMySql()) {
            return;
        }

        foreach ($this->tables() as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS `trg_{$table}_no_{$event}`");
            }
        }
    }
};
