<?php

namespace Database\Seeders;

use App\Enums\LoyaltyEarnBasis;
use App\Enums\StockTransactionType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Inventory;
use App\Models\KitchenStation;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * DEVELOPMENT DATA. Never production.
 *
 * Two branches, a small menu, recipes, ingredients with branch-specific stock,
 * demo customers and one user per role. Every email uses the reserved .test
 * domain (RFC 6761) so nothing here can ever be a real address, and the seeder
 * aborts outright if the environment is production.
 *
 * The numbers match the golden example used throughout the documentation -
 * Cappuccino 4.50 base, Large +1.00, Croissant 4.00, milk 1.30/L, coffee
 * 18.00/kg - so a manual check against docs/09-business-rules.md 4 is possible.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoDataSeeder must never run in production.');
        }

        $password = Hash::make(config('seeding.demo_password'));

        $branches = $this->seedBranches();
        $taxRate = $this->seedTaxRate();
        $this->seedLoyalty();
        $this->seedUsers($branches, $password);
        $categories = $this->seedCategories($branches);
        $products = $this->seedProducts($categories, $taxRate);
        $ingredients = $this->seedIngredients();
        $this->seedRecipes($products, $ingredients);
        $this->seedStock($branches, $ingredients);
        $this->seedCustomers();

        $this->command?->warn('Demo data seeded. Development only - do not load into production.');
        $this->command?->info('Demo accounts use the password: '.config('seeding.demo_password'));
    }

    /**
     * @return array<string, Branch>
     */
    private function seedBranches(): array
    {
        $definitions = [
            [
                'code' => 'PP1', 'name' => 'RestaurantOS Phnom Penh',
                'city' => 'Phnom Penh', 'country_code' => 'KH',
                'timezone' => 'Asia/Phnom_Penh', 'currency_code' => 'USD',
                'phone' => '+855 23 000 001',
                'opening_time' => '07:00:00', 'closing_time' => '22:00:00',
                // The trading day rolls over at 04:00, not midnight, so a sale
                // rung up at 01:30 still belongs to the night before.
                'business_day_start' => '04:00:00',
            ],
            [
                'code' => 'SR1', 'name' => 'RestaurantOS Siem Reap',
                'city' => 'Siem Reap', 'country_code' => 'KH',
                'timezone' => 'Asia/Phnom_Penh', 'currency_code' => 'USD',
                'phone' => '+855 63 000 002',
                'opening_time' => '07:00:00', 'closing_time' => '23:00:00',
                'business_day_start' => '04:00:00',
            ],
        ];

        $branches = [];

        foreach ($definitions as $definition) {
            $branch = Branch::updateOrCreate(['code' => $definition['code']], $definition);
            $branches[$definition['code']] = $branch;

            foreach ([['BAR', 'Bar', 8], ['KITCHEN', 'Kitchen', 20]] as [$code, $name, $sla]) {
                KitchenStation::updateOrCreate(
                    ['branch_id' => $branch->id, 'code' => $code],
                    ['name' => $name, 'sla_minutes' => $sla, 'is_active' => true],
                );
            }
        }

        return $branches;
    }

    private function seedTaxRate(): TaxRate
    {
        // Demo only. Production tax rates are configured per deployment; the
        // standard seeders deliberately ship none.
        return TaxRate::updateOrCreate(
            ['code' => 'VAT_STD'],
            [
                'name' => 'VAT 7%',
                'rate' => 0.0700,
                'is_inclusive' => false,
                'is_active' => true,
                'effective_from' => now()->startOfYear()->toDateString(),
            ]
        );
    }

    private function seedLoyalty(): void
    {
        $tiers = [
            ['name' => 'Bronze', 'min_lifetime_points' => 0, 'earn_rate_multiplier' => 1.0, 'sort_order' => 1],
            ['name' => 'Silver', 'min_lifetime_points' => 500, 'earn_rate_multiplier' => 1.25, 'discount_percent' => 5, 'sort_order' => 2],
            ['name' => 'Gold', 'min_lifetime_points' => 2000, 'earn_rate_multiplier' => 1.5, 'discount_percent' => 10, 'sort_order' => 3],
        ];

        foreach ($tiers as $tier) {
            LoyaltyTier::updateOrCreate(['name' => $tier['name']], $tier + ['is_active' => true]);
        }

        // The documented example rate: 1.00 spent earns 1 point, and a point is
        // worth 0.01 when redeemed.
        LoyaltyRule::updateOrCreate(
            ['name' => 'Demo standard programme'],
            [
                'earn_points_per_currency_unit' => 1.0000,
                'earn_basis' => LoyaltyEarnBasis::NetOfDiscount->value,
                'redeem_value_per_point' => 0.0100,
                'min_points_to_redeem' => 100,
                'redeem_increment' => 100,
                'max_redeem_percent_of_subtotal' => 50.00,
                'points_expiry_days' => 365,
                'is_active' => true,
                'effective_from' => now()->startOfYear()->toDateString(),
            ]
        );
    }

    /**
     * One user per operational role, plus a manager at each branch.
     *
     * @param  array<string, Branch>  $branches
     */
    private function seedUsers(array $branches, string $password): void
    {
        $roles = Role::pluck('id', 'name');

        $people = [
            ['name' => 'Dara Manager', 'email' => 'manager.pp@restaurantos.test', 'role' => 'manager', 'branch' => 'PP1', 'position' => 'Branch Manager'],
            ['name' => 'Sophea Manager', 'email' => 'manager.sr@restaurantos.test', 'role' => 'manager', 'branch' => 'SR1', 'position' => 'Branch Manager'],
            ['name' => 'Vanna Cashier', 'email' => 'cashier.pp@restaurantos.test', 'role' => 'cashier', 'branch' => 'PP1', 'position' => 'Cashier'],
            ['name' => 'Chan Cashier', 'email' => 'cashier.sr@restaurantos.test', 'role' => 'cashier', 'branch' => 'SR1', 'position' => 'Cashier'],
            ['name' => 'Rithy Kitchen', 'email' => 'kitchen.pp@restaurantos.test', 'role' => 'kitchen', 'branch' => 'PP1', 'position' => 'Line Cook'],
            ['name' => 'Nita Staff', 'email' => 'staff.pp@restaurantos.test', 'role' => 'staff', 'branch' => 'PP1', 'position' => 'Floor Staff'],
            ['name' => 'Demo Admin', 'email' => 'admin@restaurantos.test', 'role' => 'admin', 'branch' => null, 'position' => 'Operations Director'],
        ];

        foreach ($people as $index => $person) {
            $branchId = $person['branch'] ? $branches[$person['branch']]->id : null;

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => $password,
                    'employee_code' => sprintf('EMP-%03d', $index + 1),
                    'position' => $person['position'],
                    'hire_date' => now()->subMonths(6 + $index)->toDateString(),
                    'phone' => '+855 12 000 '.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'branch_id' => $branchId,
                    'is_active' => true,
                    // Demo accounts are used immediately; a forced reset would
                    // just get in the way locally.
                    'must_change_password' => false,
                    'email_verified_at' => now(),
                    'deleted_at' => null,
                ]
            );

            if (isset($roles[$person['role']])) {
                $user->roles()->syncWithoutDetaching([
                    $roles[$person['role']] => ['branch_id' => $branchId, 'created_at' => now()],
                ]);
            }
        }
    }

    /**
     * @param  array<string, Branch>  $branches
     * @return array<string, Category>
     */
    private function seedCategories(array $branches): array
    {
        $stationsByBranch = KitchenStation::where('branch_id', $branches['PP1']->id)
            ->pluck('id', 'code');

        $definitions = [
            ['name' => 'Coffee', 'slug' => 'coffee', 'station' => 'BAR', 'sort_order' => 1],
            ['name' => 'Pastries', 'slug' => 'pastries', 'station' => 'KITCHEN', 'sort_order' => 2],
            ['name' => 'Mains', 'slug' => 'mains', 'station' => 'KITCHEN', 'sort_order' => 3],
        ];

        $categories = [];

        foreach ($definitions as $definition) {
            $categories[$definition['slug']] = Category::updateOrCreate(
                ['slug' => $definition['slug'], 'deleted_at' => null],
                [
                    'name' => $definition['name'],
                    'kitchen_station_id' => $stationsByBranch[$definition['station']] ?? null,
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<string, Product>
     */
    private function seedProducts(array $categories, TaxRate $taxRate): array
    {
        $definitions = [
            [
                'sku' => 'COF-CAP', 'slug' => 'cappuccino', 'name' => 'Cappuccino',
                'category' => 'coffee', 'base_price' => 4.50, 'has_variants' => true,
                'preparation_minutes' => 4,
                'variants' => [
                    ['sku' => 'COF-CAP-R', 'name' => 'Regular', 'price_delta' => 0.00, 'is_default' => true],
                    ['sku' => 'COF-CAP-L', 'name' => 'Large', 'price_delta' => 1.00, 'is_default' => false],
                ],
            ],
            [
                'sku' => 'COF-AME', 'slug' => 'americano', 'name' => 'Americano',
                'category' => 'coffee', 'base_price' => 3.50, 'has_variants' => false,
                'preparation_minutes' => 3, 'variants' => [],
            ],
            [
                'sku' => 'PAS-CRO', 'slug' => 'croissant', 'name' => 'Croissant',
                'category' => 'pastries', 'base_price' => 4.00, 'has_variants' => false,
                'preparation_minutes' => 2, 'variants' => [],
            ],
            [
                'sku' => 'MAI-CHS', 'slug' => 'chicken-sandwich', 'name' => 'Chicken Sandwich',
                'category' => 'mains', 'base_price' => 7.50, 'has_variants' => false,
                'preparation_minutes' => 12, 'variants' => [],
            ],
        ];

        $products = [];

        foreach ($definitions as $definition) {
            $product = Product::updateOrCreate(
                ['sku' => $definition['sku'], 'deleted_at' => null],
                [
                    'category_id' => $categories[$definition['category']]->id,
                    'tax_rate_id' => $taxRate->id,
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'base_price' => $definition['base_price'],
                    'has_variants' => $definition['has_variants'],
                    'preparation_minutes' => $definition['preparation_minutes'],
                    'is_active' => true,
                    'is_available' => true,
                    'track_inventory' => true,
                ]
            );

            foreach ($definition['variants'] as $sort => $variant) {
                ProductVariant::updateOrCreate(
                    ['sku' => $variant['sku'], 'deleted_at' => null],
                    $variant + ['product_id' => $product->id, 'sort_order' => $sort, 'is_active' => true],
                );
            }

            $products[$definition['slug']] = $product;
        }

        return $products;
    }

    /**
     * @return array<string, Ingredient>
     */
    private function seedIngredients(): array
    {
        $units = Unit::pluck('id', 'code');

        $definitions = [
            ['code' => 'ING-MILK', 'name' => 'Milk', 'unit' => 'l', 'cost' => 1.3000, 'reorder' => 10, 'category' => 'Dairy', 'perishable' => true, 'shelf_life' => 7],
            ['code' => 'ING-COFFEE', 'name' => 'Coffee Beans', 'unit' => 'kg', 'cost' => 18.0000, 'reorder' => 2, 'category' => 'Dry goods', 'perishable' => false, 'shelf_life' => null],
            ['code' => 'ING-SUGAR', 'name' => 'Sugar', 'unit' => 'kg', 'cost' => 1.1000, 'reorder' => 3, 'category' => 'Dry goods', 'perishable' => false, 'shelf_life' => null],
            ['code' => 'ING-FLOUR', 'name' => 'Flour', 'unit' => 'kg', 'cost' => 0.9000, 'reorder' => 5, 'category' => 'Dry goods', 'perishable' => false, 'shelf_life' => null],
            ['code' => 'ING-BUTTER', 'name' => 'Butter', 'unit' => 'kg', 'cost' => 7.5000, 'reorder' => 2, 'category' => 'Dairy', 'perishable' => true, 'shelf_life' => 30],
            ['code' => 'ING-CHICKEN', 'name' => 'Chicken', 'unit' => 'kg', 'cost' => 5.2000, 'reorder' => 4, 'category' => 'Meat', 'perishable' => true, 'shelf_life' => 3],
            ['code' => 'ING-CHEESE', 'name' => 'Cheese', 'unit' => 'kg', 'cost' => 9.8000, 'reorder' => 2, 'category' => 'Dairy', 'perishable' => true, 'shelf_life' => 21],
        ];

        $ingredients = [];

        foreach ($definitions as $definition) {
            $ingredients[$definition['code']] = Ingredient::updateOrCreate(
                ['code' => $definition['code'], 'deleted_at' => null],
                [
                    'name' => $definition['name'],
                    'unit_id' => $units[$definition['unit']],
                    'category' => $definition['category'],
                    'default_cost_per_unit' => $definition['cost'],
                    'reorder_level' => $definition['reorder'],
                    'reorder_quantity' => $definition['reorder'] * 4,
                    'is_perishable' => $definition['perishable'],
                    'shelf_life_days' => $definition['shelf_life'],
                    'is_active' => true,
                ]
            );
        }

        return $ingredients;
    }

    /**
     * Recipes: what each product consumes, and therefore what a sale deducts.
     *
     * @param  array<string, Product>  $products
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedRecipes(array $products, array $ingredients): void
    {
        $units = Unit::pluck('id', 'code');

        $definitions = [
            // Cappuccino: the worked example from docs/09-business-rules.md.
            // 0.20 L milk at 1.30 plus 0.02 kg coffee at 18.00 = 0.62 per cup.
            'cappuccino' => [
                ['ING-MILK', 0.2000, 'l', 2.00],
                ['ING-COFFEE', 0.0200, 'kg', 0.00],
                ['ING-SUGAR', 0.0100, 'kg', 0.00],
            ],
            'americano' => [
                ['ING-COFFEE', 0.0180, 'kg', 0.00],
            ],
            'croissant' => [
                ['ING-FLOUR', 0.0800, 'kg', 5.00],
                ['ING-BUTTER', 0.0300, 'kg', 2.00],
            ],
            'chicken-sandwich' => [
                ['ING-CHICKEN', 0.1500, 'kg', 8.00],
                ['ING-CHEESE', 0.0300, 'kg', 0.00],
                ['ING-FLOUR', 0.1000, 'kg', 5.00],
            ],
        ];

        foreach ($definitions as $slug => $lines) {
            // product_variant_id stays null: one recipe covers every size, and
            // the exploder falls back to it when no variant-specific recipe
            // exists.
            $recipe = Recipe::updateOrCreate(
                [
                    'product_id' => $products[$slug]->id,
                    'product_variant_id' => null,
                    'version' => 1,
                    'deleted_at' => null,
                ],
                ['yield_quantity' => 1, 'is_active' => true]
            );

            foreach ($lines as $sort => [$code, $quantity, $unit, $wastage]) {
                RecipeItem::updateOrCreate(
                    ['recipe_id' => $recipe->id, 'ingredient_id' => $ingredients[$code]->id],
                    [
                        'quantity' => $quantity,
                        'unit_id' => $units[$unit],
                        'wastage_percent' => $wastage,
                        'is_optional' => false,
                        'sort_order' => $sort,
                    ]
                );
            }
        }
    }

    /**
     * Opening stock, written the way real stock is written: a ledger row first,
     * with the balance following it.
     *
     * Note the deliberately different quantities per branch - milk is 20 L at
     * Phnom Penh and 12 L at Siem Reap. Nothing in the schema can mix them.
     *
     * @param  array<string, Branch>  $branches
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedStock(array $branches, array $ingredients): void
    {
        $openingStock = [
            'PP1' => ['ING-MILK' => 20, 'ING-COFFEE' => 8, 'ING-SUGAR' => 6, 'ING-FLOUR' => 15, 'ING-BUTTER' => 4, 'ING-CHICKEN' => 10, 'ING-CHEESE' => 3],
            'SR1' => ['ING-MILK' => 12, 'ING-COFFEE' => 5, 'ING-SUGAR' => 4, 'ING-FLOUR' => 9, 'ING-BUTTER' => 2, 'ING-CHICKEN' => 6, 'ING-CHEESE' => 2],
        ];

        foreach ($openingStock as $branchCode => $quantities) {
            $branch = $branches[$branchCode];

            foreach ($quantities as $ingredientCode => $quantity) {
                $ingredient = $ingredients[$ingredientCode];
                $cost = (float) $ingredient->default_cost_per_unit;

                $inventory = Inventory::firstOrCreate(
                    ['branch_id' => $branch->id, 'ingredient_id' => $ingredient->id],
                );

                // Idempotency: only open the balance once.
                if ((float) $inventory->quantity_on_hand > 0) {
                    continue;
                }

                StockTransaction::create([
                    'branch_id' => $branch->id,
                    'ingredient_id' => $ingredient->id,
                    'type' => StockTransactionType::StockIn->value,
                    'quantity_change' => $quantity,
                    'unit_id' => $ingredient->unit_id,
                    'unit_cost' => $cost,
                    'total_cost' => $quantity * $cost,
                    'balance_after' => $quantity,
                    'average_cost_after' => $cost,
                    'reason' => 'Opening stock (demo data)',
                    'occurred_at' => now(),
                ]);

                $inventory->forceFill([
                    'quantity_on_hand' => $quantity,
                    'average_cost' => $cost,
                    'last_movement_at' => now(),
                ])->save();
            }
        }
    }

    private function seedCustomers(): void
    {
        $bronze = LoyaltyTier::where('name', 'Bronze')->value('id');

        $customers = [
            ['code' => 'CUS-0001', 'first_name' => 'Sokha', 'last_name' => 'Chea', 'phone' => '+855 12 345 001', 'email' => 'sokha@example.test'],
            ['code' => 'CUS-0002', 'first_name' => 'Mealea', 'last_name' => 'Pich', 'phone' => '+855 12 345 002', 'email' => 'mealea@example.test'],
            ['code' => 'CUS-0003', 'first_name' => 'Visal', 'last_name' => 'Nou', 'phone' => '+855 12 345 003', 'email' => 'visal@example.test'],
        ];

        foreach ($customers as $customer) {
            Customer::updateOrCreate(
                ['code' => $customer['code']],
                $customer + ['loyalty_tier_id' => $bronze, 'is_active' => true],
            );
        }
    }
}
