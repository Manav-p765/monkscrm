<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local demo data. Run explicitly: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder refuses to run when APP_ENV=production.');
        }

        $this->call(DatabaseSeeder::class);

        // Random per run, so there is never a well-known demo password.
        $password = Str::password(16);
        $hash = Hash::make($password);

        $manager = User::factory()->manager()->create([
            'name' => 'Demo Manager',
            'email' => 'manager@demo.test',
            'password' => $hash,
        ]);

        $sales = collect([
            ['name' => 'Demo Sales One', 'email' => 'sales1@demo.test'],
            ['name' => 'Demo Sales Two', 'email' => 'sales2@demo.test'],
        ])->map(fn (array $attrs) => User::factory()->sales()->create([...$attrs, 'password' => $hash]));

        $owners = $sales->push($manager);
        $tagIds = Tag::pluck('id');

        Lead::factory()
            ->count(50)
            ->state(fn () => [
                'created_by' => $owners->random()->id,
                'assigned_to' => fake()->boolean(85) ? $owners->random()->id : null,
            ])
            ->create()
            ->each(fn (Lead $lead) => $lead->tags()->attach($tagIds->random(rand(0, 2))));

        $this->command?->warn('Demo users created (manager@demo.test, sales1@demo.test, sales2@demo.test).');
        $this->command?->warn("Shared demo password for this run: {$password}");
    }
}
