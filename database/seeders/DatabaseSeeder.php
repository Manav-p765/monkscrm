<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only. Safe to run in every environment and safe to re-run.
     * No users are seeded: create the first admin with `php artisan crm:create-admin`.
     * Demo data lives in DemoSeeder and must be called explicitly.
     */
    public function run(): void
    {
        $this->call([
            LeadStatusSeeder::class,
            LeadSourceSeeder::class,
            TagSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
