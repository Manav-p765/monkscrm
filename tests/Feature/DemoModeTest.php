<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use RuntimeException;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    public function test_demo_mode_shows_the_neutral_brand_and_no_hybridmonks_logo(): void
    {
        foreach (['logo.png', 'favicon-32.png', 'apple-touch-icon.png'] as $file) {
            $this->assertGreaterThan(0, filesize(public_path("images/demo/{$file}")), "images/demo/{$file} is missing");
        }

        config(['crm.demo' => true, 'crm.brand_images' => 'images/demo', 'app.name' => 'Demo CRM']);

        $this->get('/login')->assertOk()
            ->assertSee('Demo CRM')
            ->assertSee(asset('images/demo/logo.png'), false)
            ->assertDontSee(asset('images/logo.png'), false)
            ->assertDontSee('favicon.ico', false)
            ->assertDontSeeText('HybridMonks')
            ->assertDontSee('hybridmonks', false);
    }

    public function test_the_default_brand_is_unchanged(): void
    {
        $this->get('/login')->assertOk()->assertSee(asset('images/logo.png'), false);
    }

    public function test_the_demo_seeder_refuses_to_run_outside_local_unless_demo_mode_is_on(): void
    {
        $this->app['env'] = 'production';
        config(['crm.demo' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DemoSeeder only runs');

        (new DemoSeeder)->run();
    }
}
