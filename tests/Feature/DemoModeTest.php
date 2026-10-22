<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockInDemo;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_demo_admins_are_not_forced_to_set_up_two_step_sign_in(): void
    {
        config(['crm.demo' => true]);

        $this->actingAs(User::factory()->admin()->withoutTwoFactor()->create())->get('/dashboard')->assertOk();
    }

    public function test_demo_mode_blocks_account_changes_invites_and_password_emails(): void
    {
        config(['crm.demo' => true]);
        $admin = User::factory()->admin()->withoutTwoFactor()->create(['password' => Hash::make('old-password-123')]);
        $other = User::factory()->sales()->create();

        $this->actingAs($admin)->from('/profile')->put('/user/password', [
            'current_password' => 'old-password-123', 'password' => 'New-password-456!', 'password_confirmation' => 'New-password-456!',
        ])->assertRedirect('/profile')->assertSessionHas('error', BlockInDemo::MESSAGE);
        $this->assertTrue(Hash::check('old-password-123', $admin->fresh()->password));

        $this->actingAs($admin)->post('/user/two-factor-authentication')->assertSessionHas('error', BlockInDemo::MESSAGE);
        $this->assertNull($admin->fresh()->two_factor_secret);

        $this->actingAs($admin)->post('/users', ['name' => 'X Y', 'email' => 'x@example.com', 'role' => 'sales'])
            ->assertSessionHas('error', BlockInDemo::MESSAGE);
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);

        $this->actingAs($admin)->post("/users/{$other->id}/deactivate")->assertSessionHas('error', BlockInDemo::MESSAGE);
        $this->assertTrue($other->fresh()->is_active);

        auth()->logout();
        $this->post('/forgot-password', ['email' => $other->email])->assertSessionHas('error', BlockInDemo::MESSAGE);
    }

    public function test_outside_demo_mode_nothing_is_blocked_and_admins_need_two_step_sign_in(): void
    {
        $this->actingAs(User::factory()->admin()->withoutTwoFactor()->create())->get('/dashboard')->assertRedirect('/profile');
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
