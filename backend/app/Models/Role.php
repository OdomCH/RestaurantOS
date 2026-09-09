<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Super Admin, Admin, Manager, Cashier, Kitchen, Staff.
 *
 * A role is a named bundle of permissions, nothing more - all authority comes
 * from the permissions attached to it, so a deployment can add a custom role
 * without a code change.
 */
#[Fillable(['name', 'display_name', 'description', 'rank'])]
class Role extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'rank' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot(['branch_id', 'assigned_by', 'created_at']);
    }

    /** System roles cannot be renamed or deleted: the code depends on the slug. */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
}
