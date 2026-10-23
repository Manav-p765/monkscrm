<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * TWO_FACTOR_ENABLED is off by default (production). phpunit.xml turns it on so the rest of the suite
 * keeps testing the two-step code; this test boots the app with it off.
 */
class TwoFactorSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->setEnv('false');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setEnv('true');
    }

    private function setEnv(string $value): void
    {
        putenv("TWO_FACTOR_ENABLED={$value}");
        $_ENV['TWO_FACTOR_ENABLED'] = $_SERVER['TWO_FACTOR_ENABLED'] = $value;
    }

    public function test_when_off_admins_sign_straight_in_and_are_not_forced_to_set_it_up(): void
    {
        $this->assertFalse(config('crm.two_factor'));
        $this->assertFalse(app('router')->has('two-factor.login'));

        // Even an admin who had set it up before is not asked for a code.
        $admin = User::factory()->admin()->create(['password' => Hash::make('Correct-Horse-9')]);
        $admin->forceFill([
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => 'Correct-Horse-9'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);

        $this->get('/dashboard')->assertOk();
        $this->get('/profile')->assertOk()->assertDontSee('Two-Step Sign-In');
    }

    public function test_sessions_last_a_week_of_inactivity(): void
    {
        // The default (Render sets no SESSION_LIFETIME); a local .env may override it.
        $this->assertStringContainsString("env('SESSION_LIFETIME', 10080)", file_get_contents(config_path('session.php')));
        $this->assertStringContainsString('SESSION_LIFETIME=10080', file_get_contents(base_path('.env.example')));
        $this->assertFalse(config('session.expire_on_close'));
    }
}
