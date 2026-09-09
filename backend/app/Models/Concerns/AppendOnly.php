<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Marks a model whose rows are facts: written once, never changed, never removed.
 *
 * Applied to the four ledgers - stock_transactions, loyalty_transactions,
 * audit_logs and order_status_histories. Corrections are made by inserting a
 * compensating row, so that both the mistake and the fix remain visible.
 *
 * This is the first of three defences (docs/22-security.md S4). It catches the
 * honest accident - a developer calling save() on a loaded ledger row. The
 * database grant and the BEFORE UPDATE trigger catch everything else.
 */
trait AppendOnly
{
    /**
     * These tables have no updated_at column: a row is never updated.
     */
    public const UPDATED_AT = null;

    protected static function bootAppendOnly(): void
    {
        static::updating(function ($model): never {
            throw new RuntimeException(
                class_basename($model).' is append-only. Insert a compensating row instead of updating.'
            );
        });

        static::deleting(function ($model): never {
            throw new RuntimeException(
                class_basename($model).' is append-only and cannot be deleted.'
            );
        });
    }
}
