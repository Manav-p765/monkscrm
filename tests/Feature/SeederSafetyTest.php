<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Production migrations run DatabaseSeeder (scripts/migrate-production.ps1): it must be safe to run
 * there, and safe to re-run. Seeders that create fake users or leads must refuse anything but local.
 */
class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return [
            'statuses' => LeadStatus::count(),
            'sources' => LeadSource::count(),
            'tags' => Tag::count(),
            'settings' => Setting::count(),
            'users' => User::count(),
            'leads' => Lead::withTrashed()->count(),
        ];
    }

    public function test_database_seeder_runs_in_production_seeds_reference_data_only_and_is_idempotent(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
            $first = $this->counts();
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame($first, $this->counts());
        $this->assertGreaterThan(0, $first['statuses']);
        $this->assertGreaterThan(0, $first['sources']);
        $this->assertGreaterThan(0, $first['tags']);
        $this->assertGreaterThan(0, $first['settings']);
        $this->assertSame(0, $first['users']);
        $this->assertSame(0, $first['leads']);
    }

    public function test_database_seeder_never_calls_the_demo_or_performance_seeders(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('DemoSeeder::class', $source);
        $this->assertStringNotContainsString('PerformanceSeeder::class', $source);
    }

    public function test_demo_and_performance_seeders_refuse_to_run_outside_local(): void
    {
        foreach ([DemoSeeder::class, PerformanceSeeder::class] as $seeder) {
            foreach (['production', 'staging', 'testing'] as $env) {
                $this->app['env'] = $env;

                try {
                    (new $seeder)->run();
                    $this->fail("{$seeder} ran with APP_ENV={$env}.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('only runs when APP_ENV=local', $e->getMessage());
                } finally {
                    $this->app['env'] = 'testing';
                }
            }
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Lead::withTrashed()->count());
    }
}
