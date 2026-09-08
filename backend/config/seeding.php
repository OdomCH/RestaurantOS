<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeding credentials
    |--------------------------------------------------------------------------
    |
    | Read through config rather than env() directly, so the values still
    | resolve when the configuration is cached (config:cache stops .env from
    | being loaded at all, and env() then returns null).
    |
    | There is deliberately no default password. SuperAdminSeeder refuses to run
    | in production or staging without one.
    |
    */

    'super_admin' => [
        'name' => env('SEED_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SEED_SUPER_ADMIN_EMAIL'),
        'password' => env('SEED_SUPER_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | Used only by DemoDataSeeder, which refuses to run outside local and
    | staging. A well-known development password is acceptable here precisely
    | because the seeder cannot reach production.
    |
    */

    'demo_password' => env('SEED_DEMO_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | Load demo data
    |--------------------------------------------------------------------------
    |
    | Local always seeds demo data. Staging seeds it only when asked. Production
    | never does - DemoDataSeeder throws if it finds itself there.
    |
    */

    'demo_data' => env('SEED_DEMO_DATA', false),

];
