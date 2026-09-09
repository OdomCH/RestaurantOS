<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kitchen display: BAR, GRILL, PASTRY. Per branch, because the grill at one
 * location is not the grill at another.
 */
#[Fillable(['branch_id', 'code', 'name', 'sla_minutes', 'is_active'])]
class KitchenStation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sla_minutes' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Categories routed to this station. */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }
}
