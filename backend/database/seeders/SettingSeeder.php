<?php

namespace Database\Seeders;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Global operational defaults.
 *
 * WHAT IS DELIBERATELY MISSING
 * ----------------------------
 * No tax rate. No service charge. No loyalty earn or redeem rate. No discount
 * ceiling. Shipping a default for any of those means a deployment that forgets
 * to configure them silently charges 0 % tax or awards points at a rate nobody
 * chose - and nobody notices until the first month-end.
 *
 * The application raises a configuration error when one of these is missing,
 * which is loud, immediate and fixable. The required keys are listed in
 * REQUIRED_BEFORE_LAUNCH below and in docs/27-environment-configuration.md.
 */
class SettingSeeder extends Seeder
{
    /**
     * Keys an operator must set before taking a real order. Seeded nowhere.
     *
     * @var array<int, string>
     */
    public const REQUIRED_BEFORE_LAUNCH = [
        'tax.default_rate_code',
        'service_charge.rate',
        'loyalty.active_rule_id',
        'discount.max_percent.manager',
        'discount.max_percent.cashier',
        'cash.drawer_variance_tolerance',
    ];

    public function run(): void
    {
        $settings = [
            // --- Point of sale ---
            ['key' => 'pos.receipt_copies', 'value' => '1', 'type' => SettingType::Integer, 'is_public' => true],
            ['key' => 'pos.require_table_for_dine_in', 'value' => '1', 'type' => SettingType::Boolean, 'is_public' => true],
            ['key' => 'pos.customer_optional', 'value' => '1', 'type' => SettingType::Boolean, 'is_public' => true],

            // --- Kitchen ---
            ['key' => 'kitchen.default_sla_minutes', 'value' => '15', 'type' => SettingType::Integer, 'is_public' => true],
            ['key' => 'kitchen.allow_skip_preparing', 'value' => '0', 'type' => SettingType::Boolean, 'is_public' => false],

            // --- Inventory ---
            // Negative stock is refused by default: a balance below zero means
            // the recipe, the count or the delivery is wrong, and carrying on
            // silently makes the error harder to find later.
            ['key' => 'inventory.allow_negative_stock', 'value' => '0', 'type' => SettingType::Boolean, 'is_public' => false],
            ['key' => 'inventory.low_stock_cooldown_minutes', 'value' => '120', 'type' => SettingType::Integer, 'is_public' => false],
            ['key' => 'inventory.block_sale_when_out_of_stock', 'value' => '0', 'type' => SettingType::Boolean, 'is_public' => false],

            // --- Orders ---
            ['key' => 'orders.require_reason_on_cancel', 'value' => '1', 'type' => SettingType::Boolean, 'is_public' => false],
            ['key' => 'orders.number_prefix_separator', 'value' => '-', 'type' => SettingType::String, 'is_public' => false],

            // --- Notifications ---
            ['key' => 'notifications.channels', 'value' => '["database"]', 'type' => SettingType::Json, 'is_public' => false],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['branch_id' => null, 'key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type']->value,
                    'is_public' => $setting['is_public'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($settings).' global settings.');
        $this->command?->warn(
            'Not seeded, and required before launch: '.implode(', ', self::REQUIRED_BEFORE_LAUNCH)
        );
    }
}
