<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Support\Alerts;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CLAUDE.md code rules that a grep can check, plus the behaviour they protect.
 */
class CodeRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_controllers_validate_with_form_requests_only(): void
    {
        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/->validate\(\s*\[/', $file->getContents(), $file->getFilename());
            $this->assertStringNotContainsString('Validator::make', $file->getContents(), $file->getFilename());
        }
    }

    public function test_status_and_assignee_are_only_written_by_their_services(): void
    {
        // Allowed: the two services, and the initial status of a brand-new lead (form, import).
        $allowed = ['LeadStatusChanger.php', 'LeadAssigner.php', 'LeadController.php:lead_status_id', 'LeadImporter.php:lead_status_id', 'LeadIntake.php:lead_status_id'];

        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();
            preg_match_all('/\$lead->(lead_status_id|assigned_to)\s*=(?!=)/', $source, $m);
            foreach ($m[1] as $column) {
                $this->assertTrue(in_array($file->getFilename(), $allowed, true) || in_array($file->getFilename().':'.$column, $allowed, true),
                    "{$file->getFilename()} writes leads.{$column} directly");
            }
            $this->assertDoesNotMatchRegularExpression('/Lead::query\(\)[^;]*->update\(\[[^\]]*(lead_status_id|assigned_to)/s', $source, $file->getFilename());
        }
    }

    public function test_no_debug_calls_or_hand_written_like_queries(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();
            $this->assertDoesNotMatchRegularExpression('/\b(dd|dump|ray|var_dump|print_r)\(/', $source, $file->getFilename());
            if ($file->getFilename() !== 'Searchable.php') {
                $this->assertDoesNotMatchRegularExpression("/whereLike|'i?like'/i", $source, $file->getFilename());
            }
        }
    }

    public function test_deactivation_reassigns_each_lead_through_the_assignment_service(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->admin()->create();
        $leaving = User::factory()->sales()->create();
        $taker = User::factory()->sales()->create(['alerts_seen_at' => now()->subDay()]);
        $open = LeadStatus::where('type', 'open')->value('id');
        $leads = Lead::factory()->count(3)->create(['assigned_to' => $leaving->id, 'lead_status_id' => $open]);

        $this->actingAs($admin)->post("/users/{$leaving->id}/deactivate", ['reassign_to' => $taker->id])->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertSame($taker->id, $lead->fresh()->assigned_to);
            $this->assertTrue(Activity::where('lead_id', $lead->id)->where('meta->event', 'assigned')->where('user_id', $admin->id)->exists());
            $this->assertTrue(AuditLog::where('action', 'lead.assigned')->where('subject_id', $lead->id)->exists());
        }
        $this->assertSame(3, Alerts::for($taker->fresh())['counts']['assigned']);
        $this->assertSame(3, AuditLog::where('action', 'user.deactivated')->first()->meta['leads_reassigned']);
    }

    public function test_a_lead_created_for_someone_else_shows_in_their_alerts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $manager = User::factory()->manager()->create();
        $sales = User::factory()->sales()->create(['alerts_seen_at' => now()->subDay()]);

        $this->actingAs($manager)->post('/leads', [
            'first_name' => 'Asha', 'email' => 'asha@example.com', 'currency' => 'INR',
            'lead_status_id' => LeadStatus::where('type', 'open')->value('id'),
            'lead_source_id' => \App\Models\LeadSource::value('id'), 'assigned_to' => $sales->id,
        ])->assertSessionHasNoErrors();

        $lead = Lead::where('email', 'asha@example.com')->first();
        $this->assertSame($sales->id, $lead->assigned_to);
        $this->assertSame(1, Alerts::for($sales->fresh())['counts']['assigned']);
        $this->assertTrue(AuditLog::where('action', 'lead.assigned')->where('subject_id', $lead->id)->exists());
    }

    public function test_panel_and_filter_inputs_get_plain_language_errors(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->admin()->create();
        $lead = Lead::factory()->create();
        $this->actingAs($admin);

        $this->from("/leads/{$lead->reference}")->patch("/leads/{$lead->reference}/status", ['lead_status_id' => 99999])
            ->assertSessionHasErrors(['lead_status_id' => 'Choose an active status.']);
        $this->from("/leads/{$lead->reference}")->patch("/leads/{$lead->reference}/priority", ['priority' => 'huge'])
            ->assertSessionHasErrors(['priority' => 'Choose a priority from the list.']);
        $this->from("/leads/{$lead->reference}")->patch("/leads/{$lead->reference}/assignee", ['assigned_to' => 99999])
            ->assertSessionHasErrors(['assigned_to' => 'Choose an active user from the list.']);
        $this->from('/reports')->get('/reports?from=2026-10-05&to=2026-10-01')
            ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
        $this->from('/follow-ups')->get('/follow-ups?tab=someday')->assertSessionHasErrors(['tab' => 'Choose a tab from the list.']);
    }
}
