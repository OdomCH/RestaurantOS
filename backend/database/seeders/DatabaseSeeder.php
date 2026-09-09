<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed`.
 *
 * Order matters: permissions before roles (roles sync grants by name), units
 * before ingredients, and everything before the demo data that depends on it.
 *
 * The reference seeders are idempotent and run in every environment, including
 * production - they carry the permission catalogue, the role matrix, the units
 * and the operational defaults, all of which the application needs to work.
 *
 * Demo data is different: it runs only in local and staging, and never without
 * being asked for in staging.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            UnitSeeder::class,
            ExpenseCategorySeeder::class,
            SettingSeeder::class,
            SuperAdminSeeder::class,
        ]);

        if (app()->environment('local') || config('seeding.demo_data')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
