<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The six system roles and their grants
 * (docs/04-user-roles-permissions.md 2 and 4).
 *
 * `rank` drives the escalation guard: nobody may grant a role at or above their
 * own rank, which is what stops a Manager from promoting themselves to Admin.
 *
 * Permissions marked in the source matrix as conditional are granted here in
 * full - the condition (own records only, a discount ceiling, the manager's own
 * branch) is a policy check, not a missing grant. A Cashier really does hold
 * orders.view; the policy narrows it to their own orders.
 *
 * Idempotent, and it re-syncs grants on every run so that the matrix in the
 * documentation is always what the database holds.
 */
class RoleSeeder extends Seeder
{
    /**
     * @return array<string, array{display_name: string, rank: int, description: string}>
     */
    private function roles(): array
    {
        return [
            'super_admin' => [
                'display_name' => 'Super Admin',
                'rank' => 100,
                'description' => 'Full system access, including global settings and impersonation.',
            ],
            'admin' => [
                'display_name' => 'Admin',
                'rank' => 80,
                'description' => 'Runs the business across all branches. Cannot edit role definitions.',
            ],
            'manager' => [
                'display_name' => 'Manager',
                'rank' => 60,
                'description' => 'Runs one branch: staff, menu, stock, approvals and reports.',
            ],
            'cashier' => [
                'display_name' => 'Cashier',
                'rank' => 40,
                'description' => 'Takes orders and payments at the POS.',
            ],
            'kitchen' => [
                'display_name' => 'Kitchen',
                'rank' => 40,
                'description' => 'Works the kitchen display and records preparation stock movements.',
            ],
            'staff' => [
                'display_name' => 'Staff',
                'rank' => 20,
                'description' => 'Limited floor access: customers and order entry only.',
            ],
        ];
    }

    /**
     * Permissions Admin does NOT get. Everything else is granted.
     *
     * Expressed as an exclusion list on purpose: a new permission added to the
     * catalogue reaches Admin automatically, which is the intended default, and
     * the four genuinely reserved capabilities stay visible in one short list.
     *
     * @return array<int, string>
     */
    private function adminExclusions(): array
    {
        return [
            'users.impersonate',      // Super Admin only - it can hide who acted.
            'roles.create',           // Role definitions are a system concern.
            'roles.update',
            'roles.delete',
            'system.manage_settings', // Global settings include tax rates.
            'system.manage_backups',
        ];
    }

    /**
     * Explicit grants for the operational roles.
     *
     * @return array<string, array<int, string>>
     */
    private function grants(): array
    {
        $everyone = [
            'profile.view', 'profile.update', 'profile.change_password',
            'branches.view', 'categories.view', 'products.view',
            'notifications.view', 'notifications.manage_preferences',
        ];

        return [
            'manager' => array_merge($everyone, [
                'users.view', 'users.create', 'users.update', 'users.activate',
                'users.assign_role', 'users.reset_password',
                'roles.view',
                'branches.update', 'branches.manage_settings',
                'categories.create', 'categories.update', 'categories.delete',
                'products.view_cost', 'products.create', 'products.update', 'products.delete',
                'products.manage_variants', 'products.manage_availability', 'products.manage_pricing',
                'customers.create', 'customers.update', 'customers.delete', 'customers.view',
                'loyalty.view', 'loyalty.redeem', 'loyalty.adjust',
                'pos.access',
                'orders.view', 'orders.view_all_users', 'orders.create', 'orders.update',
                'orders.edit_after_accept', 'orders.accept', 'orders.complete',
                'orders.cancel', 'orders.void', 'orders.apply_discount', 'orders.reprint_receipt',
                'payments.view', 'payments.create', 'payments.void', 'payments.refund',
                'payments.open_drawer', 'payments.manage_shift', 'payments.view_shift_all',
                'kitchen.access', 'kitchen.view_tickets', 'kitchen.update_ticket',
                'kitchen.skip_preparing', 'kitchen.recall_ticket', 'kitchen.manage_stations',
                'ingredients.view', 'ingredients.view_cost', 'ingredients.create',
                'ingredients.update', 'ingredients.delete',
                'recipes.view', 'recipes.create', 'recipes.update', 'recipes.delete',
                'inventory.view', 'inventory.view_valuation', 'inventory.stock_in',
                'inventory.stock_out', 'inventory.adjust', 'inventory.count',
                'inventory.view_transactions',
                'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit',
                'transfers.approve', 'transfers.dispatch', 'transfers.receive', 'transfers.cancel',
                'expenses.view', 'expenses.view_all_users', 'expenses.create', 'expenses.update',
                'expenses.delete', 'expenses.submit', 'expenses.approve',
                'reports.view_sales', 'reports.view_products', 'reports.view_profit',
                'reports.view_inventory', 'reports.view_expenses', 'reports.view_staff',
                'reports.export',
            ]),

            'cashier' => array_merge($everyone, [
                'customers.view', 'customers.create', 'customers.update',
                'loyalty.view', 'loyalty.redeem',
                'pos.access',
                // orders.view is narrowed to their own orders by the policy,
                // because orders.view_all_users is withheld.
                'orders.view', 'orders.create', 'orders.update', 'orders.accept',
                'orders.complete', 'orders.cancel', 'orders.apply_discount',
                'orders.reprint_receipt',
                'payments.view', 'payments.create', 'payments.open_drawer',
                'payments.manage_shift',
                'kitchen.access', 'kitchen.view_tickets',
                'inventory.view',
                'reports.view_sales',
            ]),

            'kitchen' => array_merge($everyone, [
                'orders.view', 'orders.accept',
                'kitchen.access', 'kitchen.view_tickets', 'kitchen.update_ticket',
                'kitchen.recall_ticket',
                'ingredients.view', 'recipes.view',
                // The kitchen records wastage and counts, but never adjusts a
                // balance outright - that is a manager action.
                'inventory.view', 'inventory.stock_out', 'inventory.count',
            ]),

            'staff' => array_merge($everyone, [
                'customers.view', 'customers.create', 'customers.update',
                'loyalty.view',
                'pos.access', 'orders.view', 'orders.create', 'orders.update',
                'kitchen.access', 'kitchen.view_tickets',
            ]),
        ];
    }

    public function run(): void
    {
        $allPermissionIds = Permission::pluck('id', 'name');

        if ($allPermissionIds->isEmpty()) {
            $this->call(PermissionSeeder::class);
            $allPermissionIds = Permission::pluck('id', 'name');
        }

        $grants = $this->grants();

        foreach ($this->roles() as $name => $attributes) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                $attributes + ['is_system' => true]
            );

            $permissionNames = match ($name) {
                'super_admin' => $allPermissionIds->keys()->all(),
                'admin' => $allPermissionIds->keys()
                    ->reject(fn (string $p) => in_array($p, $this->adminExclusions(), true))
                    ->all(),
                default => $grants[$name] ?? [],
            };

            $ids = collect($permissionNames)
                ->map(fn (string $p) => $allPermissionIds[$p] ?? null)
                ->filter()
                ->all();

            // sync, not attach: the documented matrix is the source of truth, so
            // a permission removed from it is revoked on the next deploy.
            $role->permissions()->sync($ids);

            $this->command?->info(sprintf('Role %-12s %3d permissions', $name, count($ids)));
        }
    }
}
