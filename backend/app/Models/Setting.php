<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configuration value, global when `branch_id` is null and a branch override
 * otherwise. Resolution order: branch row, then global row, then a configuration
 * error - never a silent default, because a missing tax rate must not become 0 %.
 */
#[Fillable(['branch_id', 'key', 'value', 'type', 'is_public'])]
class Setting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
