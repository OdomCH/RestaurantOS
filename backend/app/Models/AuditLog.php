<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of who did what: the admin who changed a price, the cashier
 * who cancelled an order, the manager who approved a transfer.
 *
 * `user_id` is SET NULL rather than cascade on purpose - deleting a user must
 * never erase the evidence of what they did.
 *
 * Passwords, PIN hashes and tokens are redacted before the values are written.
 */
#[Fillable([
    'user_id', 'impersonator_id', 'branch_id', 'event',
    'auditable_type', 'auditable_id', 'old_values', 'new_values',
    'description', 'ip_address', 'user_agent', 'request_id', 'route',
])]
class AuditLog extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The real actor when someone was being impersonated. */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The record that was changed. */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
