<?php

namespace Tests\Feature;

use App\Enums\IntegrationType;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationSubmission;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website form intake (POST /intake/{key}) and Settings → Integrations.
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function integration(array $attributes = []): Integration
    {
        $integration = new Integration(['name' => 'Contact – example.com', ...$attributes]);
        $integration->type = IntegrationType::Website;
        $integration->public_key = Integration::generateKey();
        $integration->secret = Integration::generateSecret();
        $integration->created_by = $this->admin->id;
        $integration->save();

        return $integration;
    }

    private function signed(Integration $integration, array $payload, ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $integration->secret);

        return $this->call('POST', "/intake/{$integration->public_key}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CRM_TIMESTAMP' => (string) $timestamp, 'HTTP_X_CRM_SIGNATURE' => $signature,
        ], $body);
    }

    // ---- Intake: authentication ---------------------------------------------------------------

    public function test_signed_post_creates_a_lead_with_the_integrations_source_and_assignee(): void
    {
        $source = LeadSource::create(['name' => 'Website – example.com', 'is_active' => true, 'sort_order' => 99]);
        $sales = User::factory()->sales()->create(['alerts_seen_at' => now()->subDay()]);
        $integration = $this->integration(['lead_source_id' => $source->id, 'assign_to' => $sales->id]);

        $this->signed($integration, [
            'your-name' => 'Asha Rao', 'your-email' => 'Asha@Example.com', 'tel' => '+91 98765 43210',
            'your-message' => 'Need SEO help', 'Budget' => '50k', 'utm_campaign' => 'diwali', 'status' => 'Won', 'assigned_to' => 'admin',
        ])->assertCreated()->assertExactJson(['ok' => true]);

        $lead = Lead::where('email', 'asha@example.com')->firstOrFail();
        $this->assertSame(['Asha', 'Rao', 'Need SEO help'], [$lead->first_name, $lead->last_name, $lead->description]);
        $this->assertSame($source->id, $lead->lead_source_id);
        $this->assertSame($source->id, $lead->original_source_id);
        $this->assertSame((int) Settings::get('default_status_id'), $lead->lead_status_id, 'status never comes from the payload');
        $this->assertSame($sales->id, $lead->assigned_to, 'assignee never comes from the payload');
        $this->assertSame($integration->id, $lead->integration_id);

        $activity = Activity::where('lead_id', $lead->id)->where('meta->event', 'captured')->firstOrFail();
        $this->assertStringContainsString('Budget: 50k', $activity->description);
        $this->assertStringContainsString('UTM campaign: diwali', $activity->description);
        $this->assertTrue(AuditLog::where('action', 'lead.captured')->where('subject_id', $lead->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'lead.assigned')->where('subject_id', $lead->id)->exists());

        $submission = IntegrationSubmission::firstOrFail();
        $this->assertSame('created', $submission->result->value);
        $this->assertContains('your-email', $submission->fields);
        $this->assertStringNotContainsString('asha', strtolower(json_encode($submission->getAttributes())), 'no submitted values are logged');
    }

    public function test_bearer_secret_works_and_form_encoded_bodies_are_read(): void
    {
        $integration = $this->integration();

        $this->withHeader('Authorization', 'Bearer '.$integration->secret)
            ->post("/intake/{$integration->public_key}", ['Name' => 'Ravi', 'Phone' => '98765 43210'])
            ->assertCreated();

        $this->assertSame('Ravi', Lead::where('phone_normalized', '9876543210')->value('first_name'));
    }

    public function test_nothing_gets_in_without_the_secret_or_an_allowed_origin(): void
    {
        $integration = $this->integration();
        $payload = ['email' => 'x@example.com'];

        $this->signed($integration, $payload, secret: 'whsec_wrong')->assertUnauthorized();
        $this->signed($integration, $payload, timestamp: time() - 600)->assertUnauthorized(); // replay window
        $this->withHeader('Authorization', 'Bearer nope')->postJson("/intake/{$integration->public_key}", $payload)->assertUnauthorized();
        // Browser post while browser posts are off.
        $this->withHeader('Origin', 'https://example.com')->postJson("/intake/{$integration->public_key}", $payload)->assertUnauthorized();
        // Unknown key, and a turned-off integration.
        $this->withHeader('Authorization', 'Bearer '.$integration->secret)->postJson('/intake/in_unknown', $payload)->assertNotFound();
        $integration->update(['is_active' => false]);
        $this->withHeader('Authorization', 'Bearer '.$integration->secret)->postJson("/intake/{$integration->public_key}", $payload)->assertNotFound();

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, IntegrationSubmission::count());
    }

    public function test_browser_posts_only_from_allowed_origins_with_cors(): void
    {
        $integration = $this->integration(['allow_browser' => true, 'allowed_origins' => ['https://example.com'], 'redirect_url' => 'https://example.com/thanks']);

        $this->withHeader('Origin', 'https://evil.test')->postJson("/intake/{$integration->public_key}", ['email' => 'a@example.com'])->assertUnauthorized();

        $this->withHeader('Origin', 'https://example.com')->postJson("/intake/{$integration->public_key}", ['email' => 'a@example.com'])
            ->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://example.com');

        // A plain HTML form goes to the thank-you page.
        $this->withHeaders(['Origin' => 'https://example.com', 'Accept' => 'text/html'])
            ->post("/intake/{$integration->public_key}", ['email' => 'b@example.com'])
            ->assertRedirect('https://example.com/thanks');

        $this->withHeader('Origin', 'https://example.com')->options("/intake/{$integration->public_key}")
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://example.com');
        $this->withHeader('Origin', 'https://evil.test')->options("/intake/{$integration->public_key}")
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->assertSame(2, Lead::count());
    }

    // ---- Intake: content ----------------------------------------------------------------------

    public function test_a_known_email_adds_to_the_existing_lead_instead_of_a_duplicate(): void
    {
        $integration = $this->integration();
        $existing = Lead::factory()->create(['email' => 'repeat@example.com']);

        $this->signed($integration, ['name' => 'Someone Else', 'email' => 'REPEAT@example.com', 'message' => 'Following up'])
            ->assertCreated()->assertExactJson(['ok' => true]); // same answer: nothing leaks

        $this->assertSame(1, Lead::count());
        $this->assertSame($existing->first_name, $existing->fresh()->first_name, 'existing data is not overwritten');
        $activity = Activity::where('lead_id', $existing->id)->where('meta->event', 'captured')->firstOrFail();
        $this->assertStringContainsString('Message: Following up', $activity->description);
        $this->assertSame('duplicate', IntegrationSubmission::first()->result->value);
    }

    public function test_missing_contact_details_are_rejected_and_logged_in_plain_language(): void
    {
        $integration = $this->integration();

        $this->signed($integration, ['name' => 'No Contact', 'message' => 'hi'])->assertStatus(422)
            ->assertJsonPath('errors.0', 'An email address or phone number is needed. No field we could read as one was sent.');

        $submission = IntegrationSubmission::firstOrFail();
        $this->assertSame('rejected', $submission->result->value);
        $this->assertSame(0, Lead::count());
    }

    public function test_bad_email_with_a_phone_keeps_the_lead(): void
    {
        $integration = $this->integration();

        $this->signed($integration, ['name' => 'Typo', 'email' => 'typo@', 'phone' => '9876543210'])->assertCreated();

        $lead = Lead::where('phone_normalized', '9876543210')->firstOrFail();
        $this->assertNull($lead->email);
        $this->assertStringContainsString('Email (not valid): typo@', Activity::where('lead_id', $lead->id)->where('meta->event', 'captured')->value('description'));
    }

    public function test_honeypot_posts_look_successful_but_create_nothing(): void
    {
        $integration = $this->integration();

        $this->signed($integration, ['email' => 'bot@example.com', '_hp' => 'http://spam'])->assertCreated();

        $this->assertSame(0, Lead::count());
        $this->assertSame('spam', IntegrationSubmission::first()->result->value);
    }

    // ---- Settings → Integrations ----------------------------------------------------------------

    public function test_only_admins_manage_integrations(): void
    {
        $integration = $this->integration();

        foreach ([User::factory()->manager()->create(), User::factory()->sales()->create()] as $user) {
            $this->actingAs($user)->get('/settings/integrations')->assertForbidden();
            $this->actingAs($user)->get("/settings/integrations/{$integration->id}")->assertForbidden();
            $this->actingAs($user)->post('/settings/integrations', ['name' => 'X'])->assertForbidden();
            $this->actingAs($user)->put("/settings/integrations/{$integration->id}", ['name' => 'X'])->assertForbidden();
            $this->actingAs($user)->post("/settings/integrations/{$integration->id}/rotate")->assertForbidden();
        }

        $this->actingAs($this->admin)->get('/settings/integrations')->assertOk()->assertSee($integration->name);
        $this->actingAs($this->admin)->get("/settings/integrations/{$integration->id}")->assertOk()->assertSee($integration->intakeUrl());
    }

    public function test_admin_adds_edits_and_rotates_an_integration_with_audit(): void
    {
        $this->actingAs($this->admin)->post('/settings/integrations', [
            'name' => 'Contact – site.in', 'allow_browser' => '1', 'allowed_origins' => "site.in\nhttps://www.site.in/contact",
        ])->assertSessionHasNoErrors();

        $integration = Integration::firstOrFail();
        $this->assertSame(['https://site.in', 'https://www.site.in'], $integration->allowed_origins);
        $this->assertStringStartsWith('whsec_', $integration->secret);
        $this->assertNotSame($integration->secret, $integration->getRawOriginal('secret'), 'secret is encrypted at rest');
        $this->assertTrue(AuditLog::where('action', 'integration.created')->exists());

        $this->actingAs($this->admin)->put("/settings/integrations/{$integration->id}", ['name' => 'Renamed', 'allow_browser' => '0', 'allowed_origins' => '', 'is_active' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($integration->fresh()->is_active);
        $this->assertTrue(AuditLog::where('action', 'integration.updated')->exists());

        $old = $integration->fresh()->secret;
        $this->actingAs($this->admin)->post("/settings/integrations/{$integration->id}/rotate")->assertRedirect();
        $this->assertNotSame($old, $integration->fresh()->secret);
        $log = AuditLog::where('action', 'integration.key_rotated')->firstOrFail();
        $this->assertStringNotContainsString('whsec_', json_encode($log->meta));
    }

    public function test_browser_posts_need_an_allowed_website_and_valid_addresses(): void
    {
        $this->actingAs($this->admin)->post('/settings/integrations', ['name' => 'X', 'allow_browser' => '1', 'allowed_origins' => ''])
            ->assertSessionHasErrorsIn('integration', ['allowed_origins']);
        $this->actingAs($this->admin)->post('/settings/integrations', ['name' => 'X', 'allowed_origins' => 'not a site!', 'redirect_url' => 'http://x.test'])
            ->assertSessionHasErrorsIn('integration', ['allowed_origins', 'redirect_url']);
        $this->assertSame(0, Integration::count());
    }
}
