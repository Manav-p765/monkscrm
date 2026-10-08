<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_returns_ok_without_session_cookies_or_database(): void
    {
        config(['session.driver' => 'database']);
        DB::enableQueryLog();

        $response = $this->get('/healthz');

        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], DB::getQueryLog(), 'healthz must not touch the database');
        $this->assertSame(0, DB::table('sessions')->count());
        // Security headers still apply (global middleware).
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_healthz_needs_no_csrf_token_and_ignores_auth(): void
    {
        $this->head('/healthz')->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get('/healthz')->assertOk();
    }

    public function test_admin_without_two_step_sign_in_is_sent_to_set_it_up(): void
    {
        $admin = User::factory()->admin()->withoutTwoFactor()->create();

        foreach (['/dashboard', '/leads', '/users', '/settings', '/audit-log', '/leads-export'] as $uri) {
            $this->actingAs($admin)->get($uri)
                ->assertRedirect('/profile')
                ->assertSessionHas('error', RequireTwoFactorForAdmins::MESSAGE);
        }

        // State-changing requests are blocked too (and nothing happens).
        $this->actingAs($admin)->post('/leads', ['first_name' => 'X'])->assertRedirect('/profile');
        $this->actingAs($admin)->getJson('/leads/live-search?q=a')->assertForbidden();
    }

    public function test_admin_without_two_step_sign_in_can_reach_profile_and_sign_out(): void
    {
        $admin = User::factory()->admin()->withoutTwoFactor()->create();

        $this->actingAs($admin)->get('/profile')->assertOk()->assertSee(RequireTwoFactorForAdmins::MESSAGE);
        $this->actingAs($admin)->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_admin_can_set_up_two_step_sign_in_and_then_use_the_app(): void
    {
        $admin = User::factory()->admin()->withoutTwoFactor()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/two-factor-authentication')->assertRedirect();
        $this->get('/user/two-factor-qr-code')->assertOk();
        $secret = decrypt($admin->fresh()->two_factor_secret);

        // Started but not confirmed: still held on the profile page.
        $this->get('/dashboard')->assertRedirect('/profile');

        $this->post('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect();
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());

        $this->get('/dashboard')->assertOk();
    }

    public function test_admin_who_turns_two_step_sign_in_off_is_held_again(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication')->assertRedirect();

        $this->get('/dashboard')->assertRedirect('/profile');
    }

    public function test_managers_and_sales_are_not_required_to_use_two_step_sign_in(): void
    {
        $this->actingAs(User::factory()->manager()->create())->get('/dashboard')->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get('/dashboard')->assertOk();
    }
}
