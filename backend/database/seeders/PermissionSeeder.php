<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The complete permission catalogue (docs/04-user-roles-permissions.md 3).
 *
 * Idempotent: safe to run on every deploy. New permissions appear, existing ones
 * have their description refreshed, and nothing is duplicated.
 *
 * Names here are binding - policies, middleware and the SPA all reference these
 * exact strings. The group is derived from the segment before the dot, so a
 * permission cannot be filed under the wrong module by accident.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Permissions that move money, destroy evidence or grant authority. Flagged
     * for an extra confirmation in the UI and heavier weight in the audit log.
     *
     * @var array<int, string>
     */
    private const DANGEROUS = [
        'users.impersonate',
        'orders.void',
        'orders.apply_discount_unlimited',
        'payments.void',
        'payments.refund',
        'inventory.adjust',
        'customers.anonymise',
        'loyalty.adjust',
        'system.manage_backups',
    ];

    /**
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        return [
            // Authentication and profile. Login and logout need no permission.
            'profile.view' => 'View own profile',
            'profile.update' => 'Update own name, phone and avatar',
            'profile.change_password' => 'Change own password',

            'users.view' => 'List and view users within branch scope',
            'users.create' => 'Create a user',
            'users.update' => 'Update user details',
            'users.delete' => 'Soft-delete a user',
            'users.activate' => 'Activate or deactivate a user',
            'users.assign_role' => 'Attach or detach roles',
            'users.reset_password' => 'Force a password reset for another user',
            'users.impersonate' => 'Act as another user for support',

            'roles.view' => 'List roles and their permissions',
            'roles.create' => 'Create a custom role',
            'roles.update' => 'Edit the permission set of a role',
            'roles.delete' => 'Delete a custom role',
            'permissions.view' => 'List the permission catalogue',

            'branches.view' => 'View branches within scope',
            'branches.view_all' => 'View every branch regardless of scope',
            'branches.create' => 'Create a branch',
            'branches.update' => 'Update a branch',
            'branches.delete' => 'Delete a branch',
            'branches.manage_settings' => 'Edit branch-level settings',

            'categories.view' => 'View categories',
            'categories.create' => 'Create a category',
            'categories.update' => 'Update a category',
            'categories.delete' => 'Delete a category',

            'products.view' => 'View products and selling prices',
            'products.view_cost' => 'View cost price and margin',
            'products.create' => 'Create a product',
            'products.update' => 'Update a product',
            'products.delete' => 'Delete a product',
            'products.manage_variants' => 'Create and edit variants',
            'products.manage_availability' => 'Toggle availability at branch level',
            'products.manage_pricing' => 'Change base price or branch price overrides',

            'customers.view' => 'View customers',
            'customers.create' => 'Create a customer',
            'customers.update' => 'Update a customer',
            'customers.delete' => 'Delete a customer',
            'customers.export' => 'Export customer personal data',
            'customers.anonymise' => 'Erase personal data, retaining financial totals',

            'loyalty.view' => 'View point balance and ledger',
            'loyalty.redeem' => 'Apply points to an order',
            'loyalty.adjust' => 'Manually credit or debit points',
            'loyalty.manage_rules' => 'Edit earn and redeem rates and tiers',

            'pos.access' => 'Open the POS terminal',
            'orders.view' => 'View orders within scope',
            'orders.view_all_users' => 'View orders created by other users',
            'orders.create' => 'Create an order',
            'orders.update' => 'Edit a pending order',
            'orders.edit_after_accept' => 'Edit an order after acceptance',
            'orders.accept' => 'Accept an order',
            'orders.complete' => 'Complete an order',
            'orders.cancel' => 'Cancel an order before completion',
            'orders.void' => 'Void an order',
            'orders.apply_discount' => 'Apply a discount up to the role threshold',
            'orders.apply_discount_unlimited' => 'Apply any discount, ignoring thresholds',
            'orders.reprint_receipt' => 'Reprint a receipt',

            'payments.view' => 'View payments on an order',
            'payments.create' => 'Take a payment',
            'payments.void' => 'Void an unsettled payment',
            'payments.refund' => 'Refund a captured payment',
            'payments.open_drawer' => 'Open the cash drawer outside a sale',
            'payments.manage_shift' => 'Open and close a cash drawer session',
            'payments.view_shift_all' => 'View shift reports of other users',

            'kitchen.access' => 'Open the kitchen display',
            'kitchen.view_tickets' => 'View tickets for the branch or station',
            'kitchen.update_ticket' => 'Move a ticket through its workflow',
            'kitchen.skip_preparing' => 'Jump a ticket from queued straight to ready',
            'kitchen.recall_ticket' => 'Move a ready ticket back to preparing',
            'kitchen.manage_stations' => 'Create and configure stations',

            'ingredients.view' => 'View ingredients',
            'ingredients.view_cost' => 'View ingredient cost per unit',
            'ingredients.create' => 'Create an ingredient',
            'ingredients.update' => 'Update an ingredient',
            'ingredients.delete' => 'Delete an ingredient',

            'recipes.view' => 'View recipes',
            'recipes.create' => 'Create a recipe',
            'recipes.update' => 'Update a recipe',
            'recipes.delete' => 'Delete a recipe',

            'inventory.view' => 'View stock levels within scope',
            'inventory.view_valuation' => 'View stock value',
            'inventory.stock_in' => 'Record incoming stock',
            'inventory.stock_out' => 'Record outgoing stock',
            'inventory.adjust' => 'Correct a balance',
            'inventory.count' => 'Record a physical stock count',
            'inventory.view_transactions' => 'Read the stock ledger',

            'transfers.view' => 'View transfers involving branches in scope',
            'transfers.create' => 'Request a transfer',
            'transfers.update' => 'Edit a draft transfer',
            'transfers.submit' => 'Submit a transfer for approval',
            'transfers.approve' => 'Approve or reject a transfer',
            'transfers.dispatch' => 'Dispatch and deduct source stock',
            'transfers.receive' => 'Receive and add destination stock',
            'transfers.cancel' => 'Cancel a transfer before dispatch',

            'expenses.view' => 'View expenses within scope',
            'expenses.view_all_users' => 'View expenses submitted by others',
            'expenses.create' => 'Create an expense',
            'expenses.update' => 'Update a draft expense',
            'expenses.delete' => 'Delete a draft expense',
            'expenses.submit' => 'Submit an expense for approval',
            'expenses.approve' => 'Approve or reject an expense',
            'expenses.mark_paid' => 'Record settlement of an expense',
            'expenses.manage_categories' => 'Manage expense categories',

            'reports.view_sales' => 'Sales summaries',
            'reports.view_products' => 'Product performance',
            'reports.view_profit' => 'Profit, COGS and margin',
            'reports.view_inventory' => 'Stock movement and valuation',
            'reports.view_expenses' => 'Expense reports',
            'reports.view_staff' => 'Per-user performance and shift reports',
            'reports.view_all_branches' => 'Cross-branch consolidated reporting',
            'reports.export' => 'Export a permitted report',

            'notifications.view' => 'View own notifications',
            'notifications.manage_preferences' => 'Configure own notification preferences',

            'audit.view' => 'Read the audit log',
            'audit.export' => 'Export audit entries',
            'system.manage_settings' => 'Edit global settings, including tax rates',
            'system.view_health' => 'View health and queue status',
            'system.manage_backups' => 'Trigger and download backups',
        ];
    }

    public function run(): void
    {
        $catalogue = $this->catalogue();

        foreach ($catalogue as $name => $description) {
            Permission::updateOrCreate(
                ['name' => $name],
                [
                    // Derived, never hand-typed: the group is always the module.
                    'group' => explode('.', $name)[0],
                    'description' => $description,
                    'is_dangerous' => in_array($name, self::DANGEROUS, true),
                ]
            );
        }

        $this->command?->info('Seeded '.count($catalogue).' permissions.');
    }
}
