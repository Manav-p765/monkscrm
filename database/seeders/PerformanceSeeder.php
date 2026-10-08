<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local performance data: 20,000 fake leads (bulk inserted). Never in production.
 *   php artisan db:seed --class=PerformanceSeeder
 */
class PerformanceSeeder extends Seeder
{
    public const COUNT = 20000;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('PerformanceSeeder refuses to run when APP_ENV=production.');
        }

        $faker = fake();
        $statuses = LeadStatus::pluck('id')->all();
        $sources = LeadSource::pluck('id')->all();
        $users = User::where('is_active', true)->pluck('id')->all();
        $creator = $users[0] ?? throw new RuntimeException('Create at least one user first (php artisan crm:create-admin).');
        $priorities = array_map(fn ($p) => $p->value, LeadPriority::cases());
        $now = now();

        foreach (array_chunk(range(1, self::COUNT), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $email = "perf{$i}.".$faker->unique()->userName().'@example.test';
                $phone = '+91 9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
                $created = $now->copy()->subMinutes(random_int(0, 60 * 24 * 365));
                $source = $sources[array_rand($sources)];
                $rows[] = [
                    'first_name' => $faker->firstName(),
                    'last_name' => $faker->lastName(),
                    'company' => $faker->company(),
                    'job_title' => $faker->jobTitle(),
                    'email' => $email,
                    'email_normalized' => Lead::normalizeEmail($email),
                    'phone' => $phone,
                    'phone_normalized' => Lead::normalizePhone($phone),
                    'city' => $faker->city(),
                    'country' => $faker->randomElement(['India', 'United States', 'United Kingdom', 'UAE', 'Singapore']),
                    'lead_status_id' => $statuses[array_rand($statuses)],
                    'lead_source_id' => $source,
                    'original_source_id' => $source,
                    'priority' => $priorities[array_rand($priorities)],
                    'assigned_to' => random_int(0, 9) ? $users[array_rand($users)] : null,
                    'created_by' => $creator,
                    'deal_value' => random_int(0, 2) ? random_int(50, 5000) * 10000 : null,
                    'currency' => random_int(0, 1) ? Currency::USD->value : Currency::INR->value,
                    'next_follow_up_at' => random_int(0, 1) ? $now->copy()->addDays(random_int(-20, 30)) : null,
                    'last_activity_at' => $created,
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }
            DB::table('leads')->insert($rows);
        }

        Lead::query()->whereNull('reference')->select('id')->chunkById(1000, function ($leads) {
            DB::transaction(function () use ($leads) {
                foreach ($leads as $lead) {
                    DB::table('leads')->where('id', $lead->id)->update(['reference' => Lead::formatReference($lead->id)]);
                }
            });
        });

        $this->command?->info(self::COUNT.' performance leads created.');
    }
}
