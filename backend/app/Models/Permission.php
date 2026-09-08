<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One granular capability, named `module.action` - products.update,
 * orders.cancel, reports.view_profit.
 *
 * Cost visibility is a separate permission from product visibility on purpose: a
 * cashier needs the menu, not the margin.
 */
#[Fillable(['name', 'group', 'description', 'is_dangerous'])]
class Permission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_dangerous' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
