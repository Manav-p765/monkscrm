<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class FoundationDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeders_create_defaults_and_no_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // idempotent

        $this->assertSame(10, LeadStatus::count());
        $this->assertSame('New', LeadStatus::ordered()->first()->name);
        $this->assertSame(['Won'], LeadStatus::where('type', 'won')->pluck('name')->all());
        $this->assertSame(11, LeadSource::count());
        $this->assertSame(5, Tag::count());
        $this->assertFalse(Setting::getValue('sales_can_view_all_leads'));
        $this->assertTrue(Setting::getValue('allow_duplicate_override'));
        $this->assertSame(0, User::count());
    }

    public function test_lead_gets_reference_normalized_contacts_and_original_source(): void
    {
        $this->seed(DatabaseSeeder::class);
        $source = LeadSource::first();

        $lead = Lead::factory()->create([
            'email' => '  Jane.Doe@Example.COM ',
            'phone' => '+91 (98765) 43210',
            'lead_source_id' => $source->id,
        ]);

        $this->assertSame(Lead::formatReference($lead->id), $lead->fresh()->reference);
        $this->assertMatchesRegularExpression('/^LD-\d{6}$/', $lead->fresh()->reference);
        $this->assertSame('jane.doe@example.com', $lead->email_normalized);
        $this->assertSame('919876543210', $lead->phone_normalized);
        $this->assertSame($source->id, $lead->original_source_id);
    }

    public function test_original_source_cannot_change_after_creation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $lead = Lead::factory()->create(['lead_source_id' => LeadSource::first()->id]);

        // Changing the current source is fine.
        $lead->update(['lead_source_id' => LeadSource::latest('id')->first()->id]);

        $this->expectException(LogicException::class);
        $lead->forceFill(['original_source_id' => LeadSource::latest('id')->first()->id])->save();
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::create(['action' => 'auth.login', 'meta' => ['x' => 1]]);

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Audit log update should throw.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_create_admin_command_creates_active_admin(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $this->artisan('crm:create-admin')
            ->expectsQuestion('Name', 'Asha Admin')
            ->expectsQuestion('Email', 'Asha@HybridMonks.com')
            ->expectsQuestion('Password', 'Very-Strong-Passw0rd')
            ->expectsQuestion('Confirm password', 'Very-Strong-Passw0rd')
            ->assertSuccessful();

        $admin = User::where('email', 'asha@hybridmonks.com')->firstOrFail();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('Very-Strong-Passw0rd', $admin->password));
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            (new DemoSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
