<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for the backed string enums that mirror the database
 * `VARCHAR(30)` + `CHECK` status columns (docs/05-database-design.md §4).
 */
trait HasValues
{
    /**
     * Every case value, in declaration order.
     *
     * Used by validation rules, by the CHECK-constraint migration and by the
     * seeders, so that a status vocabulary is declared exactly once.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The values rendered as a quoted, comma-separated SQL list.
     *
     * Example: `'pending','accepted'`.
     */
    public static function sqlList(): string
    {
        return "'".implode("','", self::values())."'";
    }

    /**
     * Whether the given raw value is a member of this enum.
     */
    public static function isValid(?string $value): bool
    {
        return $value !== null && self::tryFrom($value) !== null;
    }
}
