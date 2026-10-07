<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Models\LeadSource;
use App\Models\LeadStatus;
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
            // Currencies leads may use (subset of App\Enums\Currency) and the default for new leads.
            'enabled_currencies' => Currency::DEFAULT_ENABLED,
            'default_currency' => Currency::DEFAULT,
            // Sales see unassigned leads (shared pool) unless an admin turns this off.
            'sales_can_view_unassigned' => true,
            // Defaults for new leads (must point at an active status / source).
            'default_status_id' => LeadStatus::query()->whereRaw('lower(name) = ?', ['new'])->value('id'),
            'default_source_id' => LeadSource::query()->whereRaw('lower(name) = ?', ['manual entry'])->value('id'),
            // Import limits (admin-adjustable up to 20 MB / 20,000 rows).
            'import_max_file_mb' => 5,
            'import_max_rows' => 5000,
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
