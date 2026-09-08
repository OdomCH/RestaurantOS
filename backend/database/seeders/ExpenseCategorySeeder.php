<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * The standard expense categories.
 *
 * `is_cogs_related` decides which side of the gross-margin line a cost falls on
 * in the profit report, so it is set deliberately rather than defaulted.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'RENT', 'name' => 'Rent', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'ELECTRICITY', 'name' => 'Electricity', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'WATER', 'name' => 'Water', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'TRANSPORT', 'name' => 'Transportation', 'is_cogs_related' => false, 'requires_approval' => true],
            // Kitchen supplies are consumed producing food, so they belong above
            // the margin line with COGS.
            ['code' => 'SUPPLIES', 'name' => 'Supplies', 'is_cogs_related' => true, 'requires_approval' => true],
            ['code' => 'MAINTENANCE', 'name' => 'Maintenance', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'SALARIES', 'name' => 'Salaries and Wages', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'MARKETING', 'name' => 'Marketing', 'is_cogs_related' => false, 'requires_approval' => true],
            ['code' => 'OTHER', 'name' => 'Other', 'is_cogs_related' => false, 'requires_approval' => true],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::updateOrCreate(
                ['code' => $category['code']],
                $category + ['is_active' => true],
            );
        }

        $this->command?->info('Seeded '.count($categories).' expense categories.');
    }
}
