<?php

namespace Database\Seeders;

use App\Models\LeadStatus;
use Illuminate\Database\Seeder;

class LeadStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['New', '#2563eb', 'open'],
            ['Attempted Contact', '#7c3aed', 'open'],
            ['Contacted', '#0891b2', 'open'],
            ['Follow-Up Required', '#d97706', 'open'],
            ['Qualified', '#0d9488', 'open'],
            ['Opportunity', '#4f46e5', 'open'],
            ['Won', '#16a34a', 'won'],
            ['Lost', '#dc2626', 'lost'],
            ['Not Interested', '#9f1239', 'lost'],
            ['Invalid', '#6b7280', 'lost'],
        ];

        foreach ($statuses as $i => [$name, $color, $type]) {
            LeadStatus::firstOrCreate(
                ['name' => $name],
                ['color' => $color, 'type' => $type, 'sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
