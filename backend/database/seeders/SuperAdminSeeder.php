<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the one account that can bootstrap everything else.
 *
 * NO SHIPPED PASSWORD, EVER
 * -------------------------
 * There is no admin/admin123 here and there never will be. A hard-coded
 * credential in a seeder reaches production sooner or later, and a Super Admin
 * password is the whole system.
 *
 * Behaviour depends on the environment:
 *   production / staging - SEED_SUPER_ADMIN_PASSWORD must be set, or the seeder
 *                          refuses to run.
 *   local / testing      - if unset, a random password is generated and printed
 *                          once. It is never written to a file or committed.
 *
 * The account is always created with must_change_password, so whatever it starts
 * as does not survive the first login.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('seeding.super_admin.email');
        $name = config('seeding.super_admin.name');
        $password = config('seeding.super_admin.password');
        $generated = false;

        if (blank($email)) {
            if (app()->environment(['production', 'staging'])) {
                throw new RuntimeException(
                    'SEED_SUPER_ADMIN_EMAIL must be set before seeding in this environment.'
                );
            }

            // .test is reserved for testing by RFC 6761 and cannot be a real
            // address, which makes it obvious this is development data.
            $email = 'super.admin@restaurantos.test';
        }

        if (blank($password)) {
            if (app()->environment(['production', 'staging'])) {
                throw new RuntimeException(
                    'SEED_SUPER_ADMIN_PASSWORD must be set before seeding in this environment. '.
                    'Refusing to create an administrator with a default password.'
                );
            }

            $password = Str::password(24);
            $generated = true;
        }

        $role = Role::where('name', 'super_admin')->first();

        if (! $role) {
            $this->call(RoleSeeder::class);
            $role = Role::where('name', 'super_admin')->firstOrFail();
        }

        $user = User::withTrashed()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
                'branch_id' => null,   // Super Admin is not tied to one branch.
                'deleted_at' => null,
            ]
        );

        // Global grant: branch_id null on the pivot means every branch.
        $user->roles()->syncWithoutDetaching([
            $role->id => ['branch_id' => null, 'created_at' => now()],
        ]);

        $this->command?->info("Super Admin ready: {$email}");

        if ($generated) {
            $this->command?->warn('DEVELOPMENT ONLY - generated password: '.$password);
            $this->command?->warn('It is shown once and must be changed at first login.');
        }
    }
}
