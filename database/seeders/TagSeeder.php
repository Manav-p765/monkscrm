<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            'Hot Lead' => '#dc2626',
            'VIP' => '#7c3aed',
            'Enterprise' => '#1d4ed8',
            'Follow Up' => '#d97706',
            'Existing Customer' => '#16a34a',
        ];

        foreach ($tags as $name => $color) {
            Tag::firstOrCreate(['name' => $name], ['color' => $color]);
        }
    }
}
