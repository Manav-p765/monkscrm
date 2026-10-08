<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Leads\LeadAssigner;
use App\Support\Alerts;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadWorkTest extends TestCase
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
        Settings::update(['sales_can_view_unassigned' => false]);
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->sales = User::factory()->sales()->create();
        $this->otherSales = User::factory()->sales()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id, 'next_follow_up_at' => null,
            'lead_status_id' => LeadStatus::where('name', 'New')->value('id'),
            'last_contacted_at' => null, 'first_contacted_at' => null,
        ]);
    }

    // ---- Notes ---------------------------------------------------------------------------------

    public function test_notes_add_edit_within_15_minutes_and_admin_soft_delete(): void
    {
        $lead = $this->lead($this->sales);
        $this->actingAs($this->sales)->post("/leads/{$lead->reference}/notes", ['body' => 'Called reception'])->assertRedirect();
        $note = LeadNote::first();
        $this->assertSame($this->sales->id, $note->user_id);

        $this->put("/notes/{$note->id}", ['body' => 'Called reception twice'])->assertRedirect();
        $this->assertSame('Called reception twice', $note->fresh()->body);

        Carbon::setTestNow(now()->addMinutes(16));
        $this->put("/notes/{$note->id}", ['body' => 'Too late'])->assertSessionHas('error');
        $this->assertSame('Called reception twice', $note->fresh()->body);

        // Someone else can't edit it; sales can't delete; others who can't see the lead get 404.
        $this->actingAs($this->otherSales)->put("/notes/{$note->id}", ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->sales)->delete("/notes/{$note->id}")->assertForbidden();

        $this->actingAs($this->admin)->delete("/notes/{$note->id}")->assertRedirect();
        $this->assertSoftDeleted($note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.note_deleted']);
    }

    public function test_cannot_add_notes_or_activities_to_invisible_leads(): void
    {
        $lead = $this->lead($this->otherSales);
        $this->actingAs($this->sales);

        $this->post("/leads/{$lead->reference}/notes", ['body' => 'x'])->assertNotFound();
        $this->post("/leads/{$lead->reference}/activities", ['type' => 'call', 'description' => 'x'])->assertNotFound();
        $this->post("/leads/{$lead->reference}/follow-ups", ['type' => 'call', 'assigned_to' => $this->sales->id, 'due_at' => '2026-12-01T10:00'])->assertNotFound();
        $this->assertSame(0, LeadNote::count() + FollowUp::count());
    }

    // ---- Activities ----------------------------------------------------------------------------

    public function test_logging_a_call_updates_contact_dates_in_ist(): void
    {
        Carbon::setTestNow('2026-10-08 06:00:00'); // 11:30 IST
        $lead = $this->lead($this->sales);

        $this->actingAs($this->sales)->post("/leads/{$lead->reference}/activities", [
            'type' => 'call', 'occurred_at' => '2026-10-08T10:15', 'description' => 'Discussed pricing', 'outcome' => 'Wants a proposal',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('2026-10-08 04:45:00', $lead->last_contacted_at->toDateTimeString()); // 10:15 IST = 04:45 UTC
        $this->assertSame('2026-10-08 04:45:00', $lead->first_contacted_at->toDateTimeString());
        $this->assertSame('Wants a proposal', Activity::where('meta->event', 'logged')->first()->outcome);

        // "Other" doesn't count as contact; a later call doesn't move first_contacted_at.
        $this->post("/leads/{$lead->reference}/activities", ['type' => 'other', 'description' => 'Researched company'])->assertSessionHasNoErrors();
        $this->post("/leads/{$lead->reference}/activities", ['type' => 'email', 'occurred_at' => '2026-10-08T11:00', 'description' => 'Sent deck'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-08 04:45:00', $lead->fresh()->first_contacted_at->toDateTimeString());
        $this->assertSame('2026-10-08 05:30:00', $lead->fresh()->last_contacted_at->toDateTimeString());

        $this->post("/leads/{$lead->reference}/activities", ['type' => 'call', 'occurred_at' => '2026-10-09T10:00', 'description' => 'Future'])
            ->assertSessionHasErrorsIn('activity', 'occurred_at');
    }

    public function test_activity_can_schedule_a_follow_up(): void
    {
        $lead = $this->lead($this->sales);
        $this->actingAs($this->sales)->post("/leads/{$lead->reference}/activities", [
            'type' => 'meeting', 'description' => 'Demo done', 'schedule_follow_up' => '1',
            'follow_up_type' => 'proposal', 'follow_up_due_at' => '2026-12-01T10:00',
        ])->assertSessionHasNoErrors();

        $fu = FollowUp::first();
        $this->assertSame($this->sales->id, $fu->assigned_to);
        $this->assertSame('2026-12-01 04:30:00', $fu->due_at->toDateTimeString());
        $this->assertSame('2026-12-01 04:30:00', $lead->fresh()->next_follow_up_at->toDateTimeString());
    }

    // ---- Follow-ups ----------------------------------------------------------------------------

    public function test_next_follow_up_stays_in_sync_through_the_whole_lifecycle(): void
    {
        $lead = $this->lead($this->sales);
        $this->actingAs($this->sales);
        $schedule = fn (string $due) => $this->post("/leads/{$lead->reference}/follow-ups",
            ['type' => 'call', 'assigned_to' => $this->sales->id, 'due_at' => $due])->assertSessionHasNoErrors();

        $schedule('2026-12-05T10:00');
        $schedule('2026-12-01T10:00');
        [$late, $early] = [FollowUp::orderBy('id')->first(), FollowUp::orderByDesc('id')->first()];
        $next = fn () => $lead->fresh()->next_follow_up_at?->toDateTimeString();
        $this->assertSame('2026-12-01 04:30:00', $next());

        $this->patch("/follow-ups/{$early->id}", ['action' => 'complete', 'outcome' => 'Spoke to CFO'])->assertRedirect();
        $this->assertSame(FollowUpStatus::Completed, $early->fresh()->status);
        $this->assertSame('2026-12-05 04:30:00', $next());
        $this->assertNotNull($lead->fresh()->last_contacted_at); // outcome logged a call

        $this->patch("/follow-ups/{$late->id}", ['action' => 'reschedule', 'due_at' => '2026-12-03T09:00'])->assertRedirect();
        $this->assertSame('2026-12-03 03:30:00', $next());

        $this->patch("/follow-ups/{$late->id}", ['action' => 'cancel'])->assertRedirect();
        $this->assertNull($next());

        // Reopen a completed one: it's pending again and drives next_follow_up_at.
        $this->patch("/follow-ups/{$early->id}", ['action' => 'reopen'])->assertRedirect();
        $this->assertSame(FollowUpStatus::Pending, $early->fresh()->status);
        $this->assertNull($early->fresh()->completed_at);
        $this->assertSame('2026-12-01 04:30:00', $next());

        // Can't complete twice / reopen a pending one.
        $this->patch("/follow-ups/{$early->id}", ['action' => 'reopen'])->assertSessionHas('error');
    }

    public function test_follow_up_assignee_must_see_the_lead_and_sales_only_assign_themselves(): void
    {
        $lead = $this->lead($this->sales);

        $this->actingAs($this->sales)->post("/leads/{$lead->reference}/follow-ups", ['type' => 'call', 'assigned_to' => $this->otherSales->id, 'due_at' => '2026-12-01T10:00'])
            ->assertSessionHasErrorsIn('followUp', 'assigned_to');

        // A manager can assign to someone who can see the lead, but not to a sales user who can't.
        $this->actingAs($this->manager)->post("/leads/{$lead->reference}/follow-ups", ['type' => 'call', 'assigned_to' => $this->otherSales->id, 'due_at' => '2026-12-01T10:00'])
            ->assertSessionHasErrorsIn('followUp', 'assigned_to');
        $this->post("/leads/{$lead->reference}/follow-ups", ['type' => 'demo', 'assigned_to' => $this->sales->id, 'due_at' => '2026-12-01T10:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, FollowUp::count());

        // Only the assignee, the creator or an admin/manager may act on it.
        $fu = FollowUp::first();
        $third = User::factory()->sales()->create();
        Settings::update(['sales_can_view_all_leads' => true]);
        $this->actingAs($third)->patch("/follow-ups/{$fu->id}", ['action' => 'cancel'])->assertForbidden();
        $this->actingAs($this->sales)->patch("/follow-ups/{$fu->id}", ['action' => 'cancel'])->assertRedirect();
    }

    public function test_follow_ups_page_tabs_overdue_in_ist_and_who_sees_what(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00'); // 17:30 IST
        $mine = $this->lead($this->sales);
        $theirs = $this->lead($this->otherSales);
        $make = fn (Lead $lead, User $who, string $dueUtc, string $status = 'pending') => FollowUp::forceCreate([
            'lead_id' => $lead->id, 'assigned_to' => $who->id, 'created_by' => $who->id, 'type' => 'call', 'due_at' => $dueUtc, 'status' => $status,
        ]);
        $overdue = $make($mine, $this->sales, '2026-10-08 11:00:00');
        $today = $make($mine, $this->sales, '2026-10-08 18:00:00');      // 23:30 IST, still today
        $upcoming = $make($mine, $this->sales, '2026-10-08 18:31:00');   // 00:01 IST tomorrow
        $done = $make($mine, $this->sales, '2026-10-01 10:00:00', 'completed');
        $other = $make($theirs, $this->otherSales, '2026-10-08 11:00:00');

        $ids = fn (string $tab, ?User $as = null, string $extra = '') => $this->actingAs($as ?? $this->sales)
            ->get("/follow-ups?tab={$tab}{$extra}")->assertOk()->viewData('followUps')->pluck('id')->all();

        $this->assertSame([$overdue->id], $ids('overdue'));
        $this->assertSame([$today->id], $ids('today'));
        $this->assertSame([$upcoming->id], $ids('upcoming'));
        $this->assertSame([$done->id], $ids('completed'));

        // Admins/managers see everyone's, with an assignee filter.
        $this->assertEqualsCanonicalizing([$overdue->id, $other->id], $ids('overdue', $this->manager));
        $this->assertSame([$other->id], $ids('overdue', $this->manager, '&assigned='.$this->otherSales->id));
    }

    public function test_alerts_count_my_due_follow_ups_and_new_assignments_respecting_visibility(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $lead = $this->lead($this->sales);
        FollowUp::forceCreate(['lead_id' => $lead->id, 'assigned_to' => $this->sales->id, 'created_by' => $this->sales->id, 'type' => 'call', 'due_at' => '2026-10-08 10:00:00', 'status' => 'pending']);
        FollowUp::forceCreate(['lead_id' => $lead->id, 'assigned_to' => $this->sales->id, 'created_by' => $this->sales->id, 'type' => 'call', 'due_at' => '2026-10-08 15:00:00', 'status' => 'pending']);
        // A follow-up on a lead the user can no longer see doesn't count.
        $hidden = $this->lead($this->otherSales);
        FollowUp::forceCreate(['lead_id' => $hidden->id, 'assigned_to' => $this->sales->id, 'created_by' => $this->sales->id, 'type' => 'call', 'due_at' => '2026-10-08 10:00:00', 'status' => 'pending']);

        app(LeadAssigner::class)->assign($this->lead(), $this->sales, $this->manager, 'panel');

        $alerts = Alerts::for($this->sales->fresh());
        $this->assertSame(['overdue' => 1, 'today' => 1, 'assigned' => 1], $alerts['counts']);
        $this->actingAs($this->sales)->get('/dashboard')->assertSee('Alerts: 3');

        $this->post('/alerts/seen')->assertRedirect();
        $this->assertSame(0, Alerts::for($this->sales->fresh())['counts']['assigned']);
    }
}
