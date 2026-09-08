<?php

use App\Enums\CashDrawerSessionStatus;
use App\Enums\DiscountType;
use App\Enums\ExpenseStatus;
use App\Enums\KitchenTicketItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\LoyaltyEarnBasis;
use App\Enums\LoyaltyTransactionType;
use App\Enums\NotificationSeverity;
use App\Enums\OrderItemStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SettingType;
use App\Enums\StockTransactionType;
use App\Enums\TransferStatus;
use App\Enums\UnitFamily;
use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Value-level constraints, applied last so that every table exists.
 *
 * TWO KINDS OF CHECK LIVE HERE
 * ----------------------------
 * 1. Status vocabularies. Status columns are VARCHAR(30) + CHECK rather than
 *    MySQL ENUM: adding a value to an ENUM needs an ALTER TABLE that locks the
 *    table, which is unacceptable on an 800k-row `orders`. The permitted values
 *    come from the PHP enums in app/Enums, so the database and the application
 *    cannot drift apart.
 *
 * 2. Arithmetic sanity. Negative money, zero-quantity ledger rows and a transfer
 *    from a branch to itself are all impossible states; the database refuses
 *    them even when a console command or a background job bypasses validation.
 *
 * MySQL only. SQLite cannot ADD CONSTRAINT to an existing table, so on the test
 * connection these are skipped and the equivalent rules are covered by
 * application validation. Any test asserting a CHECK must run against MySQL
 * (docs/database/business-constraints.md 6).
 */
return new class extends Migration
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function constraints(): array
    {
        $in = fn (string $column, array $values) => "`{$column}` IN ('".implode("','", $values)."')";
        $inNullable = fn (string $column, array $values) => "`{$column}` IS NULL OR ".$in($column, $values);

        return [
            // --- Status vocabularies -------------------------------------
            'chk_orders_status' => ['orders', $in('status', OrderStatus::values())],
            'chk_orders_payment_status' => ['orders', $in('payment_status', OrderPaymentStatus::values())],
            'chk_orders_order_type' => ['orders', $in('order_type', OrderType::values())],
            'chk_orders_discount_type' => ['orders', $in('discount_type', DiscountType::values())],
            'chk_order_items_status' => ['order_items', $in('status', OrderItemStatus::values())],
            'chk_order_items_discount_type' => ['order_items', $in('discount_type', DiscountType::values())],
            'chk_payments_method' => ['payments', $in('method', PaymentMethod::values())],
            'chk_payments_status' => ['payments', $in('status', PaymentStatus::values())],
            'chk_refunds_method' => ['refunds', $in('method', PaymentMethod::values())],
            'chk_stock_transactions_type' => ['stock_transactions', $in('type', StockTransactionType::values())],
            'chk_stock_transfers_status' => ['stock_transfers', $in('status', TransferStatus::values())],
            'chk_expenses_status' => ['expenses', $in('status', ExpenseStatus::values())],
            'chk_expenses_payment_method' => ['expenses', $inNullable('payment_method', PaymentMethod::values())],
            'chk_kitchen_tickets_status' => ['kitchen_tickets', $in('status', KitchenTicketStatus::values())],
            'chk_kitchen_ticket_items_status' => ['kitchen_ticket_items', $in('status', KitchenTicketItemStatus::values())],
            'chk_loyalty_transactions_type' => ['loyalty_transactions', $in('type', LoyaltyTransactionType::values())],
            'chk_loyalty_rules_earn_basis' => ['loyalty_rules', $in('earn_basis', LoyaltyEarnBasis::values())],
            'chk_units_family' => ['units', $in('family', UnitFamily::values())],
            'chk_cash_drawer_sessions_status' => ['cash_drawer_sessions', $in('status', CashDrawerSessionStatus::values())],
            'chk_notifications_severity' => ['notifications', $in('severity', NotificationSeverity::values())],
            'chk_settings_type' => ['settings', $in('type', SettingType::values())],
            'chk_daily_sequences_scope' => ['daily_sequences', $in('scope', ['order', 'expense', 'transfer', 'refund', 'payment'])],

            // --- Money and quantity sanity -------------------------------
            'chk_products_base_price' => ['products', '`base_price` >= 0'],
            'chk_orders_amounts' => ['orders', '`subtotal` >= 0 AND `discount_amount` >= 0 AND `tax_amount` >= 0 AND `grand_total` >= 0 AND `paid_total` >= 0 AND `refunded_total` >= 0'],
            // A discount can never exceed what was actually ordered.
            'chk_orders_discount_bound' => ['orders', '`discount_amount` <= `subtotal`'],
            'chk_order_items_quantity' => ['order_items', '`quantity` > 0'],
            'chk_order_items_unit_price' => ['order_items', '`unit_price` >= 0'],
            'chk_order_items_discount_bound' => ['order_items', '`line_discount_amount` <= `line_subtotal`'],
            'chk_payments_amount' => ['payments', '`amount` > 0'],
            // Cash tendered must cover what was applied to the order.
            'chk_payments_tendered' => ['payments', '`tendered_amount` IS NULL OR `tendered_amount` >= `amount`'],
            'chk_payments_refunded_bound' => ['payments', '`refunded_total` >= 0 AND `refunded_total` <= `amount`'],
            'chk_refunds_amount' => ['refunds', '`amount` > 0'],
            'chk_expenses_amounts' => ['expenses', '`amount` > 0 AND `tax_amount` >= 0'],
            'chk_expenses_total' => ['expenses', '`total_amount` = `amount` + `tax_amount`'],
            'chk_customers_points' => ['customers', '`loyalty_points_balance` >= 0'],
            'chk_recipe_items_quantity' => ['recipe_items', '`quantity` > 0'],
            'chk_recipe_items_wastage' => ['recipe_items', '`wastage_percent` >= 0 AND `wastage_percent` < 100'],
            'chk_recipes_yield' => ['recipes', '`yield_quantity` > 0'],
            'chk_units_conversion_factor' => ['units', '`conversion_factor` > 0'],
            'chk_ingredients_reorder_level' => ['ingredients', '`reorder_level` >= 0'],
            // A zero movement is a bug, not a fact.
            'chk_stock_transactions_nonzero' => ['stock_transactions', '`quantity_change` <> 0'],
            // FR-TRF-001: a branch cannot transfer stock to itself.
            'chk_stock_transfers_branches' => ['stock_transfers', '`from_branch_id` <> `to_branch_id`'],
            'chk_stock_transfer_items_requested' => ['stock_transfer_items', '`requested_quantity` > 0'],
        ];
    }

    public function up(): void
    {
        foreach ($this->constraints() as $name => [$table, $expression]) {
            SchemaSupport::check($table, $name, $expression);
        }
    }

    public function down(): void
    {
        foreach ($this->constraints() as $name => [$table, $expression]) {
            SchemaSupport::dropCheck($table, $name);
        }
    }
};
