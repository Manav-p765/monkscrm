<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\ProductionGuard;
use RuntimeException;
use Tests\TestCase;

class ProductionGuardTest extends TestCase
{
    private function productionConfig(array $overrides = []): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.debug' => false,
            'app.url' => 'https://crm.hybridmonks.com',
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => 'postgresql://USER:PASSWORD@db.invalid/neondb?sslmode=require',
            ...$overrides,
        ]);
    }

    public function test_a_complete_production_config_has_no_problems(): void
    {
        $this->productionConfig();

        $this->assertSame([], ProductionGuard::problems());
    }

    public function test_each_missing_or_unsafe_setting_is_reported_by_name(): void
    {
        $cases = [
            'APP_KEY is not set' => ['app.key' => null],
            'APP_DEBUG must be false' => ['app.debug' => true],
            'APP_URL is not set' => ['app.url' => 'http://localhost'],
            'APP_URL must start with https://' => ['app.url' => 'http://crm.hybridmonks.com'],
            'DB_URL is not set' => ['database.connections.pgsql.url' => null],
        ];

        foreach ($cases as $problem => $override) {
            $this->productionConfig($override);
            $this->assertSame([$problem], ProductionGuard::problems(), $problem);
        }
    }

    public function test_the_app_refuses_to_boot_in_production_and_does_not_reveal_values(): void
    {
        $this->productionConfig(['app.url' => 'http://crm.hybridmonks.com', 'app.debug' => true]);
        $this->app['env'] = 'production';

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Expected the app to refuse to boot.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG must be false', $e->getMessage());
            $this->assertStringContainsString('APP_URL must start with https://', $e->getMessage());
            $this->assertStringNotContainsString('crm.hybridmonks.com', $e->getMessage());
            $this->assertStringNotContainsString('base64:', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_guard_does_not_run_outside_production(): void
    {
        config(['app.key' => null, 'app.debug' => true, 'app.url' => 'http://localhost']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertNotEmpty(ProductionGuard::problems());
    }
}
