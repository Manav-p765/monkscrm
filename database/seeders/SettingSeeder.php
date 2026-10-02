<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Defaults only; existing values are never overwritten.
     */
    public function run(): void
    {
        $defaults = [
            'sales_can_view_all_leads' => false,
            // Applies to managers/admins only; sales users can never override a duplicate warning.
            'allow_duplicate_override' => true,
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
