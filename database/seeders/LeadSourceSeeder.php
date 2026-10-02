<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            'Website',
            'Manual Entry',
            'CSV/Excel Import',
            'Referral',
            'Google',
            'Social Media',
            'Email Campaign',
            'Event',
            'Partner',
            'Existing Customer',
            'Other',
        ];

        foreach ($sources as $i => $name) {
            LeadSource::firstOrCreate(
                ['name' => $name],
                ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
