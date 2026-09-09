<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers for the parts of the schema that cannot be expressed portably by the
 * Laravel schema builder.
 *
 * RestaurantOS targets **MySQL 8** in every real environment
 * (docs/05-database-design.md §1.1). The automated test suite, however, runs on
 * SQLite for speed. Three constructs differ between the two:
 *
 * | Construct          | MySQL                    | SQLite                          |
 * |--------------------|--------------------------|---------------------------------|
 * | CHECK constraints  | `ALTER TABLE ... ADD`    | no `ADD CONSTRAINT` at all      |
 * | FULLTEXT indexes   | supported                | not supported                   |
 * | Triggers           | used for ledger locking  | different syntax, not needed    |
 * | Generated columns  | `CONCAT(...)`            | `... || ...`                    |
 *
 * Rather than pretend the two are the same, the MySQL-only constructs are
 * applied conditionally and the difference is stated in
 * docs/database/business-constraints.md §6. Any test that asserts a CHECK
 * constraint, a generated column or FULLTEXT search **must** run against MySQL.
 */
final class SchemaSupport
{
    /**
     * Whether the connection being migrated is MySQL (or MariaDB).
     */
    public static function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Add a named CHECK constraint. No-op on drivers that cannot add one.
     */
    public static function check(string $table, string $name, string $expression): void
    {
        if (! self::isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /**
     * Drop a named CHECK constraint. No-op on drivers that never added one.
     */
    public static function dropCheck(string $table, string $name): void
    {
        if (! self::isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
    }

    /**
     * String concatenation for a generated column, in the current dialect.
     *
     * @param  array<int, string>  $parts
     */
    public static function concat(array $parts): string
    {
        return self::isMySql()
            ? 'CONCAT('.implode(', ', $parts).')'
            : implode(' || ', $parts);
    }

    /**
     * A generated-column expression that maps `NULL` to `0`.
     *
     * MySQL treats every `NULL` in a unique index as distinct, so a nullable
     * column cannot take part in a uniqueness rule directly. Folding `NULL` to a
     * sentinel in a stored generated column restores the intended constraint —
     * see `role_user` and `settings`.
     */
    public static function nullSafe(string $column, string $sentinel = '0'): string
    {
        return "IFNULL(`{$column}`, {$sentinel})";
    }
}
