<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\User;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pre-launch security review (2026-10-09): IDOR for every record type, CSRF, no state changes on
 * GET, XSS in pages and fragments, mass assignment, encryption at rest, rate limits.
 */
class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $sales;

    private User $otherSales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Settings::update(['sales_can_view_unassigned' => false, 'sales_can_view_all_leads' => false]);
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->sales = User::factory()->sales()->create();
        $this->otherSales = User::factory()->sales()->create();
    }

    private function lead(?User $owner, array $attributes = []): Lead
    {
        return Lead::factory()->create(['assigned_to' => $owner?->id, ...$attributes]);
    }

    // ---- IDOR: one test per record type ---------------------------------------------------------

    public function test_sales_cannot_reach_another_users_lead_through_any_lead_route(): void
    {
        $theirs = $this->lead($this->otherSales);
        $before = $theirs->only(['first_name', 'lead_status_id', 'assigned_to', 'priority']);
        $ref = $theirs->reference;
        $status = LeadStatus::where('is_active', true)->where('id', '!=', $theirs->lead_status_id)->value('id');

        $this->actingAs($this->sales);
        $this->get("/leads/{$ref}")->assertNotFound();
        $this->get("/leads/{$ref}/edit")->assertNotFound();
        $this->put("/leads/{$ref}", ['first_name' => 'Hacked', 'email' => 'h@example.com', 'lead_status_id' => $status,
            'lead_source_id' => $theirs->lead_source_id, 'currency' => 'USD'])->assertNotFound();
        $this->patch("/leads/{$ref}/status", ['lead_status_id' => $status])->assertNotFound();
        $this->patch("/leads/{$ref}/assignee", ['assigned_to' => $this->sales->id])->assertNotFound();
        $this->patch("/leads/{$ref}/priority", ['priority' => 'urgent'])->assertNotFound();
        $this->delete("/leads/{$ref}")->assertNotFound();
        $this->post("/leads/{$ref}/notes", ['body' => 'x'])->assertNotFound();
        $this->post("/leads/{$ref}/activities", ['type' => 'call', 'description' => 'x'])->assertNotFound();
        $this->post("/leads/{$ref}/follow-ups", ['type' => 'call', 'assigned_to' => $this->sales->id, 'due_at' => '2030-01-01T10:00'])->assertNotFound();

        // Bulk with their lead's ID: silently skipped, nothing changes.
        $this->post('/leads/bulk', ['ids' => [$theirs->id], 'op' => "status:{$status}"])->assertSessionHas('status');
        $this->post('/leads/bulk', ['ids' => [$theirs->id], 'op' => "assign:{$this->sales->id}"]);

        $this->assertSame($before, $theirs->fresh()->only(['first_name', 'lead_status_id', 'assigned_to', 'priority']));
        $this->assertSame(0, LeadNote::count() + FollowUp::count());
        $this->assertSame(0, Activity::where('lead_id', $theirs->id)->where('user_id', $this->sales->id)->count());
    }

    public function test_sales_cannot_edit_or_delete_a_note_on_a_lead_they_cannot_see(): void
    {
        $theirs = $this->lead($this->otherSales);
        $note = $theirs->notes()->make(['body' => 'Private']);
        $note->user_id = $this->otherSales->id;
        $note->save();

        $this->actingAs($this->sales)->put("/notes/{$note->id}", ['body' => 'Changed'])->assertNotFound();
        $this->actingAs($this->sales)->delete("/notes/{$note->id}")->assertNotFound();
        // A manager can see the lead but may not edit someone else's note.
        $this->actingAs($this->manager)->put("/notes/{$note->id}", ['body' => 'Changed'])->assertSessionHas('error');
        $this->assertSame('Private', $note->fresh()->body);
        $this->assertNull($note->fresh()->deleted_at);
    }

    public function test_sales_cannot_act_on_follow_ups_that_are_not_theirs(): void
    {
        $theirs = $this->lead($this->otherSales);
        $hidden = $this->followUp($theirs, $this->otherSales);
        $this->actingAs($this->sales)->patch("/follow-ups/{$hidden->id}", ['action' => 'complete'])->assertNotFound();
        $this->actingAs($this->sales)->patch("/follow-ups/{$hidden->id}", ['action' => 'reschedule', 'due_at' => '2030-01-01T10:00'])->assertNotFound();

        // Visible lead (unassigned leads visible), but someone else's follow-up: forbidden.
        Settings::update(['sales_can_view_unassigned' => true]);
        $shared = $this->followUp($this->lead(null), $this->otherSales);
        $this->actingAs($this->sales)->patch("/follow-ups/{$shared->id}", ['action' => 'cancel'])->assertForbidden();

        $this->assertSame(FollowUpStatus::Pending, $hidden->fresh()->status);
        $this->assertSame(FollowUpStatus::Pending, $shared->fresh()->status);
    }

    public function test_activities_and_timeline_of_hidden_leads_are_never_shown(): void
    {
        $theirs = $this->lead($this->otherSales, ['first_name' => 'Hiddenperson']);
        $activity = $theirs->activities()->make(['type' => 'call', 'description' => 'Secret call notes', 'occurred_at' => now()]);
        $activity->user_id = $this->otherSales->id;
        $activity->save();

        $this->actingAs($this->sales);
        $this->get('/dashboard')->assertOk()->assertDontSee('Secret call notes')->assertDontSee('Hiddenperson');
        $this->get('/leads?q=Hiddenperson')->assertOk()->assertDontSee($theirs->reference);
        $this->get('/leads/live-search?q=Hiddenperson')->assertOk()->assertDontSee($theirs->reference)->assertSee('Found 0');
        $this->get('/pipeline')->assertOk()->assertDontSee('Hiddenperson');
        $this->get('/follow-ups')->assertOk()->assertDontSee('Hiddenperson');
    }

    public function test_a_manager_cannot_reach_another_managers_import_by_id(): void
    {
        $import = new Import(['original_filename' => 'theirs.csv', 'status' => 'validated', 'column_mapping' => ['Email' => 'email']]);
        $import->user_id = $this->manager->id;
        $import->save();
        $other = User::factory()->manager()->create();

        $this->actingAs($other);
        $this->get("/imports/{$import->id}")->assertNotFound();
        $this->post("/imports/{$import->id}/mapping", ['mapping' => ['email']])->assertNotFound();
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'update'])->assertNotFound();
        $this->post("/imports/{$import->id}/run")->assertNotFound();
        $this->post("/imports/{$import->id}/cancel")->assertNotFound();
        $this->post("/imports/{$import->id}/undo")->assertNotFound();
        $this->get("/imports/{$import->id}/errors.csv")->assertNotFound();
        $this->get('/imports?tab=history')->assertOk()->assertDontSee('theirs.csv');
        $this->assertSame('validated', $import->fresh()->status);
    }

    public function test_managers_and_sales_cannot_change_a_user_by_id(): void
    {
        $target = $this->otherSales;
        $payload = ['name' => 'Renamed', 'email' => $target->email, 'role' => 'admin'];

        foreach ([$this->manager, $this->sales] as $actor) {
            $this->actingAs($actor);
            $this->put("/users/{$target->id}", $payload)->assertForbidden();
            $this->post("/users/{$target->id}/deactivate", [])->assertForbidden();
            $this->post("/users/{$target->id}/password-reset")->assertForbidden();
            $this->delete("/users/{$target->id}/sessions")->assertForbidden();
            $this->delete("/users/{$target->id}/two-factor")->assertForbidden();
        }
        $this->actingAs($this->sales)->get("/users/{$target->id}/edit")->assertForbidden();
        $this->actingAs($this->manager)->get("/users/{$target->id}/edit")->assertForbidden();

        $this->assertSame('sales', $target->fresh()->role->value);
        $this->assertTrue($target->fresh()->is_active);
    }

    // ---- Mass assignment ------------------------------------------------------------------------

    public function test_status_assignee_and_system_fields_are_not_mass_assignable(): void
    {
        $lead = new Lead(['first_name' => 'A', 'lead_status_id' => 99, 'assigned_to' => 99, 'next_follow_up_at' => now(),
            'created_by' => 99, 'reference' => 'LD-X', 'won_at' => now(), 'import_id' => 99]);
        $this->assertSame(['first_name' => 'A'], $lead->getAttributes());

        foreach (File::allFiles(app_path('Models')) as $file) {
            $source = $file->getContents();
            $this->assertStringNotContainsString('$guarded = []', $source, $file->getFilename());
        }
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertDoesNotMatchRegularExpression('/(create|update|fill|forceFill|make)\(\s*\$request->all\(\)/', $file->getContents(), $file->getFilename());
        }
    }

    // ---- CSRF and GET ---------------------------------------------------------------------------

    public function test_every_state_changing_route_rejects_a_request_without_a_csrf_token(): void
    {
        // Laravel skips CSRF checks in tests; switch them back on for this test.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->admin);
        $lead = $this->lead(null);

        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
            if ($methods === [] || $route->uri() === '/') {
                continue;
            }
            $uri = preg_replace(['/\{lead\}/', '/\{[^}]+\}/'], [$lead->reference, '1'], $route->uri());
            foreach ($methods as $method) {
                $this->call($method, '/'.ltrim($uri, '/'))->assertStatus(419);
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function test_get_routes_are_a_reviewed_read_only_list(): void
    {
        $get = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && count($r->methods()) <= 2)
            ->map(fn (Route $r) => $r->uri())->sort()->values()->all();

        // Each of these only reads. A new GET route must be reviewed (no state changes on GET) and added here.
        $reviewed = ['audit-log', 'audit-log/{auditLog}', 'dashboard', 'follow-ups', 'forgot-password', 'healthz', 'imports',
            'imports/template.csv', 'imports/{import}', 'imports/{import}/errors.csv', 'invite/{token}', 'leads', 'leads-export',
            'leads/create', 'leads/duplicate-check', 'leads/live-search', 'leads/{lead}', 'leads/{lead}/edit', 'login', 'pipeline',
            'profile', 'reports', 'reports/export', 'reset-password/{token}', 'settings', 'settings/general', 'settings/permissions',
            'settings/sources', 'settings/statuses', 'settings/tags', 'two-factor-challenge', 'user/confirm-password',
            'user/confirmed-password-status', 'user/two-factor-qr-code', 'user/two-factor-recovery-codes', 'user/two-factor-secret-key',
            'users', 'users/{user}/deactivate', 'users/{user}/edit', 'up', 'storage/{path}', '{fallbackPlaceholder}'];

        $this->assertSame([], array_values(array_diff($get, $reviewed)), 'Unreviewed GET routes');
    }

    // ---- XSS ------------------------------------------------------------------------------------

    public function test_user_content_is_escaped_in_pages_and_html_fragments(): void
    {
        $xss = '<script>alert(1)</script>';
        $lead = $this->lead($this->sales, ['first_name' => $xss, 'company' => '<img src=x onerror=alert(1)>', 'email' => 'xss@example.com',
            'website' => 'javascript:alert(1)']);
        $note = $lead->notes()->make(['body' => $xss]);
        $note->user_id = $this->sales->id;
        $note->save();

        $this->actingAs($this->sales);
        foreach (["/leads/{$lead->reference}?tab=details", "/leads/{$lead->reference}?tab=notes", '/leads', '/pipeline',
            '/leads/live-search?q=xss', '/leads/duplicate-check?email=xss@example.com'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('href="javascript:', $html, $url);
        }
    }

    public function test_raw_blade_output_is_limited_to_reviewed_places(): void
    {
        $raw = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match_all('/\{!!\s*(.+?)\s*!!\}/', $file->getContents(), $m)) {
                foreach ($m[1] as $expression) {
                    $raw[] = $file->getRelativePathname().': '.$expression;
                }
            }
        }
        // Static icon SVG strings and the 2FA QR code SVG (generated by Fortify): no user content.
        $allowed = [
            'pipeline'.DIRECTORY_SEPARATOR.'index.blade.php: $icons[\'grip\']',
            'pipeline'.DIRECTORY_SEPARATOR.'index.blade.php: $icons[\'building\']',
            'pipeline'.DIRECTORY_SEPARATOR.'index.blade.php: $icons[\'clock\']',
            'profile'.DIRECTORY_SEPARATOR.'show.blade.php: $user->twoFactorQrCodeSvg()',
        ];
        $this->assertSame([], array_values(array_diff($raw, $allowed)));
        $this->assertStringNotContainsString('x-html', implode('', array_map(fn ($f) => $f->getContents(), File::allFiles(resource_path('views')))));
    }

    public function test_fragments_and_error_pages_carry_the_security_headers(): void
    {
        $this->actingAs($this->sales);
        foreach (['/leads/live-search?q=ab', '/leads/duplicate-check?email=a@example.com', '/no-such-page', '/settings'] as $url) {
            $response = $this->get($url);
            $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'), $url);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $url);
        }
    }

    // ---- Encryption at rest ---------------------------------------------------------------------

    public function test_two_factor_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->sales)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')->assertRedirect();

        $row = DB::table('users')->where('id', $this->sales->id)->first();
        $this->assertNotNull($row->two_factor_secret);
        $secret = decrypt($row->two_factor_secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $secret);
        $this->assertStringNotContainsString($secret, $row->two_factor_secret);
        $this->assertIsArray(json_decode(decrypt($row->two_factor_recovery_codes), true));
    }

    public function test_import_staging_never_stores_contact_details_in_plain_text(): void
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['Name', 'Email', 'Phone'], escape: '');
        fputcsv($fh, ['Ann Lee', 'not-an-email@@example.com', '12'], escape: '');
        fputcsv($fh, ['Bo Ray', 'bo.ray@example.com', '+1 555 0100'], escape: '');
        rewind($fh);
        $file = UploadedFile::fake()->createWithContent('leads.csv', stream_get_contents($fh));

        $this->actingAs($this->admin)->post('/imports', ['file' => $file])->assertSessionHasNoErrors();
        $import = Import::latest('id')->first();
        $this->post("/imports/{$import->id}/mapping", ['mapping' => ['full_name', 'email', 'phone']])->assertSessionHasNoErrors();

        $raw = json_encode(DB::table('import_rows')->get()->all());
        foreach (['not-an-email@@example.com', 'bo.ray@example.com', '555 0100'] as $value) {
            $this->assertStringNotContainsString($value, $raw);
        }
        $this->assertSame(1, ImportRow::where('action', 'error')->count());
    }

    public function test_export_audit_never_stores_the_search_text(): void
    {
        $this->lead(null, ['email' => 'secret.person@example.com']);
        $this->actingAs($this->admin)->get('/leads-export?q=secret.person@example.com')->assertOk();

        $log = AuditLog::where('action', 'leads.exported')->latest('id')->first();
        $this->assertStringNotContainsString('secret.person', json_encode($log->meta));
        $this->assertSame('(search)', $log->meta['filters']['q']);
    }

    // ---- Rate limits ----------------------------------------------------------------------------

    public function test_forgot_password_and_password_confirmation_are_rate_limited(): void
    {
        Notification::fake();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/forgot-password', ['email' => "nobody{$i}@example.com"])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertTooManyRequests();

        $this->actingAs($this->sales);
        for ($i = 0; $i < 10; $i++) {
            $this->post('/user/confirm-password', ['password' => 'Wrong-Password-1']);
        }
        $this->post('/user/confirm-password', ['password' => UserFactory::PASSWORD])->assertTooManyRequests();
    }

    public function test_login_is_limited_per_ip_across_many_emails(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post('/login', ['email' => "guess{$i}@example.com", 'password' => 'Wrong-Password-1'], ['REMOTE_ADDR' => '203.0.113.9']);
        }
        $this->from('/login')->post('/login', ['email' => $this->sales->email, 'password' => UserFactory::PASSWORD], ['REMOTE_ADDR' => '203.0.113.9'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ---- Invite links ---------------------------------------------------------------------------

    public function test_invite_answers_do_not_reveal_whether_an_account_exists(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
        $answer = function (string $email) {
            $this->from('/invite/bad')->post('/invite', ['token' => 'bad', 'email' => $email,
                'password' => 'A-Strong-Passw0rd!x', 'password_confirmation' => 'A-Strong-Passw0rd!x'])
                ->assertRedirect('/invite/bad')->assertSessionHasErrors('email');

            return session('errors')->first('email');
        };

        $this->assertSame($answer($this->sales->email), $answer('nobody@example.com'));
    }

    // ---- Routes that had no test ----------------------------------------------------------------

    public function test_two_step_sign_in_challenge_accepts_only_a_valid_code_and_recovery_codes_once(): void
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $this->actingAs($this->sales)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/user/two-factor-authentication')->assertRedirect();
        $secret = decrypt($this->sales->fresh()->two_factor_secret);
        $this->get('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg']);
        $this->get('/user/two-factor-secret-key')->assertOk();
        $this->post('/user/confirmed-two-factor-authentication', ['code' => $google2fa->getCurrentOtp($secret)])->assertRedirect();
        $this->assertNotNull($this->sales->fresh()->two_factor_confirmed_at);
        $recovery = $this->getJson('/user/two-factor-recovery-codes')->assertOk()->json()[0];
        $this->post('/logout');

        // Password alone is not enough: the challenge comes next, and a wrong code fails.
        $this->post('/login', ['email' => $this->sales->email, 'password' => UserFactory::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->post('/login', ['email' => $this->sales->email, 'password' => UserFactory::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->sales);
        $this->post('/logout');

        // A recovery code works once.
        $this->post('/login', ['email' => $this->sales->email, 'password' => UserFactory::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery]);
        $this->assertGuest();
    }

    public function test_reset_password_page_and_import_template_render_safely(): void
    {
        $this->get('/reset-password/some-token?email=a@example.com')->assertOk()->assertSee('a@example.com');
        $this->get('/reset-password/x?email="><script>alert(1)</script>')->assertOk()->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs($this->sales)->get('/imports/template.csv')->assertForbidden();
        $csv = $this->actingAs($this->manager)->get('/imports/template.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith('"First Name","Last Name",Company', $csv);
        $this->assertStringContainsString(',"+91 98765 43210",', $csv);
        $this->assertStringContainsString('@example.com', $csv);
    }

    public function test_sources_reorder_and_move_are_admin_only(): void
    {
        $ids = \App\Models\LeadSource::ordered()->pluck('id')->all();
        $this->actingAs($this->manager)->post('/settings/sources/reorder', ['ids' => array_reverse($ids)])->assertForbidden();
        $this->actingAs($this->manager)->post("/settings/sources/{$ids[0]}/move", ['direction' => 'down'])->assertForbidden();
        $this->assertSame($ids, \App\Models\LeadSource::ordered()->pluck('id')->all());

        $this->actingAs($this->admin)->post('/settings/sources/reorder', ['ids' => array_reverse($ids)])->assertRedirect();
        $this->assertSame(array_reverse($ids), \App\Models\LeadSource::ordered()->pluck('id')->all());
        $this->post("/settings/sources/{$ids[count($ids) - 1]}/move", ['direction' => 'down'])->assertRedirect();
        $this->assertSame($ids[count($ids) - 1], \App\Models\LeadSource::ordered()->pluck('id')->all()[1]);
    }

    private function followUp(Lead $lead, User $assignee): FollowUp
    {
        $followUp = $lead->followUps()->make(['assigned_to' => $assignee->id, 'type' => FollowUpType::Call, 'due_at' => now()->addDay(),
            'status' => FollowUpStatus::Pending]);
        $followUp->created_by = $assignee->id;
        $followUp->save();

        return $followUp;
    }
}
