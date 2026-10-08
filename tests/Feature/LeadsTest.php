<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadsTest extends TestCase
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
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->sales = User::factory()->sales()->create();
        $this->otherSales = User::factory()->sales()->create();
    }

    /** @return array{mine: Lead, unassigned: Lead, theirs: Lead} */
    private function threeLeads(): array
    {
        return [
            'mine' => Lead::factory()->create(['assigned_to' => $this->sales->id, 'first_name' => 'Mina', 'last_name' => 'Mine', 'email' => 'mina@example.com']),
            'unassigned' => Lead::factory()->create(['assigned_to' => null, 'first_name' => 'Una', 'last_name' => 'Nobody', 'email' => 'una@example.com']),
            'theirs' => Lead::factory()->create(['assigned_to' => $this->otherSales->id, 'first_name' => 'Theo', 'last_name' => 'Theirs', 'email' => 'theo@example.com']),
        ];
    }

    private function validLead(array $overrides = []): array
    {
        return [
            'first_name' => 'Priya', 'last_name' => 'Shah', 'company' => 'Acme', 'email' => '  Priya.Shah@Acme.COM ',
            'phone' => '+91 (98765) 43210', 'lead_status_id' => Settings::get('default_status_id'),
            'lead_source_id' => Settings::get('default_source_id'), 'currency' => 'INR', 'deal_value' => '2,50,000.50',
            'priority' => 'high', ...$overrides,
        ];
    }

    // ---- Visibility matrix ---------------------------------------------------------------------

    public function test_visibility_matrix_in_list_search_panel_and_pipeline(): void
    {
        $leads = $this->threeLeads();

        $cases = [
            // [settings, user, visible keys]
            [['sales_can_view_unassigned' => true, 'sales_can_view_all_leads' => false], $this->sales, ['mine', 'unassigned']],
            [['sales_can_view_unassigned' => false, 'sales_can_view_all_leads' => false], $this->sales, ['mine']],
            [['sales_can_view_unassigned' => false, 'sales_can_view_all_leads' => true], $this->sales, ['mine', 'unassigned', 'theirs']],
            [['sales_can_view_unassigned' => false, 'sales_can_view_all_leads' => false], $this->manager, ['mine', 'unassigned', 'theirs']],
            [['sales_can_view_unassigned' => false, 'sales_can_view_all_leads' => false], $this->admin, ['mine', 'unassigned', 'theirs']],
        ];

        foreach ($cases as $i => [$settings, $user, $visible]) {
            Settings::update($settings);
            $this->actingAs($user);

            $listed = $this->get('/leads')->assertOk()->viewData('leads')->pluck('id')->all();
            $kanban = $this->get('/pipeline')->assertOk()->viewData('columns')->flatMap(fn ($c) => $c['cards']->pluck('id'))->all();
            $search = $this->get('/leads/live-search?q=example.com')->assertOk()->getContent();

            foreach ($leads as $key => $lead) {
                $should = in_array($key, $visible, true);
                $this->assertSame($should, in_array($lead->id, $listed, true), "case {$i} list {$key}");
                $this->assertSame($should, in_array($lead->id, $kanban, true), "case {$i} kanban {$key}");
                $this->assertSame($should, str_contains($search, $lead->reference), "case {$i} search {$key}");
                $this->get("/leads/{$lead->reference}")->assertStatus($should ? 200 : 404);
                $this->get("/leads/{$lead->reference}/edit")->assertStatus($should ? 200 : 404);
            }
        }
    }

    public function test_invisible_lead_is_404_for_every_action(): void
    {
        $theirs = $this->threeLeads()['theirs'];
        $this->actingAs($this->sales);
        $ref = $theirs->reference;

        $this->put("/leads/{$ref}", $this->validLead())->assertNotFound();
        $this->patch("/leads/{$ref}/status", ['lead_status_id' => LeadStatus::first()->id])->assertNotFound();
        $this->patch("/leads/{$ref}/assignee", ['assigned_to' => $this->sales->id])->assertNotFound();
        $this->patch("/leads/{$ref}/priority", ['priority' => 'low'])->assertNotFound();
        $this->delete("/leads/{$ref}")->assertNotFound();
        $this->assertSame($this->otherSales->id, $theirs->fresh()->assigned_to);
    }

    public function test_search_needs_two_characters_matches_phone_digits_and_full_name(): void
    {
        $lead = Lead::factory()->create(['first_name' => 'Victoria', 'last_name' => 'Sterling', 'phone' => '+1 (415) 890-1122', 'assigned_to' => null]);
        $this->actingAs($this->admin);

        $this->assertStringNotContainsString($lead->reference, $this->get('/leads/live-search?q=V')->getContent());
        $this->assertStringContainsString($lead->reference, $this->get('/leads/live-search?q=victoria sterling')->getContent());
        $this->assertStringContainsString($lead->reference, $this->get('/leads/live-search?q=415-890')->getContent());
        $this->assertStringContainsString($lead->reference, $this->get('/leads/live-search?q='.strtolower($lead->reference))->getContent());
        $this->assertSame([$lead->id], $this->get('/leads?q=8901122')->viewData('leads')->pluck('id')->all());
    }

    public function test_live_endpoints_are_rate_limited_and_need_login(): void
    {
        $this->get('/leads/live-search?q=ab')->assertRedirect(route('login'));

        $this->actingAs($this->admin);
        for ($i = 0; $i < 60; $i++) {
            $this->get('/leads/live-search?q=ab')->assertOk();
        }
        $this->get('/leads/live-search?q=ab')->assertStatus(429);
    }

    // ---- Create / edit -------------------------------------------------------------------------

    public function test_create_stores_normalized_contacts_money_and_defaults(): void
    {
        $tag = Tag::first();
        $this->actingAs($this->sales)
            ->post('/leads', $this->validLead(['tags' => [$tag->id], 'initial_note' => 'Met at the expo']))
            ->assertSessionHasNoErrors();

        $lead = Lead::latest('id')->first();
        $this->assertSame('priya.shah@acme.com', $lead->email_normalized);
        $this->assertSame('919876543210', $lead->phone_normalized);
        $this->assertSame(25000050, $lead->deal_value);
        $this->assertSame('INR', $lead->currency->value);
        $this->assertSame($this->sales->id, $lead->created_by);
        $this->assertSame($this->sales->id, $lead->assigned_to);
        $this->assertSame([$tag->id], $lead->tags->pluck('id')->all());
        $this->assertSame('Met at the expo', $lead->notes()->first()->body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.created', 'subject_id' => $lead->id]);
        $this->assertTrue(Activity::where('lead_id', $lead->id)->where('meta->event', 'created')->exists());

        // The form defaults come from settings.
        $form = $this->get('/leads/create')->assertOk();
        $form->assertSee('value="'.Settings::get('default_status_id').'" selected', false);
    }

    public function test_validation_requires_a_name_or_company_and_an_email_or_phone(): void
    {
        $this->actingAs($this->admin);

        $this->post('/leads', $this->validLead(['first_name' => '', 'company' => '', 'email' => '', 'phone' => '']))
            ->assertSessionHasErrors(['first_name', 'company', 'email', 'phone']);
        $this->post('/leads', $this->validLead(['first_name' => '', 'email' => '']))->assertSessionHasNoErrors();
        $this->post('/leads', $this->validLead(['email' => 'not-an-email', 'phone' => '']))->assertSessionHasErrors('email');
        $this->post('/leads', $this->validLead(['website' => 'javascript:alert(1)']))->assertSessionHasErrors('website');
        $this->post('/leads', $this->validLead(['deal_value' => '12.345', 'email' => 'a@b.co', 'phone' => '']))->assertSessionHasErrors('deal_value');
    }

    public function test_currency_must_be_enabled_but_a_lead_keeps_a_disabled_one(): void
    {
        $this->actingAs($this->admin);
        $this->post('/leads', $this->validLead(['currency' => 'EUR']))->assertSessionHasErrors('currency');

        $lead = Lead::factory()->create(['currency' => 'GBP', 'deal_value' => 100000]);
        $this->put("/leads/{$lead->reference}", $this->validLead(['currency' => 'GBP', 'email' => 'other@x.com', 'phone' => '']))->assertSessionHasNoErrors();
        $this->assertSame('GBP', $lead->fresh()->currency->value);
    }

    public function test_mass_assignment_of_ownership_fields_is_ignored(): void
    {
        $this->actingAs($this->sales)->post('/leads', $this->validLead([
            'created_by' => $this->admin->id, 'reference' => 'LD-999999', 'original_source_id' => LeadSource::latest('id')->value('id'),
            'won_at' => now()->toDateTimeString(), 'email_normalized' => 'x@y.z',
        ]))->assertSessionHasNoErrors();

        $lead = Lead::latest('id')->first();
        $this->assertSame($this->sales->id, $lead->created_by);
        $this->assertNotSame('LD-999999', $lead->reference);
        $this->assertSame((int) Settings::get('default_source_id'), $lead->original_source_id);
        $this->assertNull($lead->won_at);
        $this->assertSame('priya.shah@acme.com', $lead->email_normalized);
    }

    public function test_sales_can_only_assign_to_themselves_admins_and_managers_to_anyone(): void
    {
        $this->actingAs($this->sales)->post('/leads', $this->validLead(['assigned_to' => $this->otherSales->id]))->assertSessionHasErrors('assigned_to');

        $lead = Lead::factory()->create(['assigned_to' => null]);
        $this->patch("/leads/{$lead->reference}/assignee", ['assigned_to' => $this->otherSales->id])->assertForbidden();
        $this->patch("/leads/{$lead->reference}/assignee", ['assigned_to' => $this->sales->id])->assertRedirect();
        $this->assertSame($this->sales->id, $lead->fresh()->assigned_to);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.assigned', 'subject_id' => $lead->id]);

        $this->actingAs($this->manager)->patch("/leads/{$lead->reference}/assignee", ['assigned_to' => $this->otherSales->id])->assertRedirect();
        $this->assertSame($this->otherSales->id, $lead->fresh()->assigned_to);
    }

    public function test_update_logs_changed_field_names_status_and_assignment(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->sales->id, 'first_name' => 'Old', 'company' => 'Acme', 'email' => 'x@acme.com', 'phone' => null]);
        $qualified = LeadStatus::where('name', 'Qualified')->first();

        $this->actingAs($this->manager)->put("/leads/{$lead->reference}", $this->validLead([
            'first_name' => 'New', 'email' => 'x@acme.com', 'phone' => '', 'lead_status_id' => $qualified->id,
            'assigned_to' => $this->otherSales->id, 'lead_source_id' => $lead->lead_source_id ?? Settings::get('default_source_id'),
        ]))->assertSessionHasNoErrors();

        $fields = AuditLog::where('action', 'lead.updated')->first()->meta['fields'];
        $this->assertContains('first_name', $fields);
        $this->assertNotContains('assigned_to', $fields);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.status_changed', 'subject_id' => $lead->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.assigned', 'subject_id' => $lead->id]);
        $this->assertNotNull($lead->fresh()->qualified_at);
    }

    // ---- Duplicates ----------------------------------------------------------------------------

    public function test_duplicate_check_never_leaks_details_of_leads_you_cannot_see(): void
    {
        ['theirs' => $theirs, 'mine' => $mine] = $this->threeLeads();
        Settings::update(['sales_can_view_unassigned' => false]);
        $this->actingAs($this->sales);

        $hidden = $this->get('/leads/duplicate-check?email=THEO@example.com')->assertOk()->getContent();
        $this->assertStringContainsString('A lead with this email already exists', $hidden);
        $this->assertStringNotContainsString('Theo', $hidden);
        $this->assertStringNotContainsString($theirs->reference, $hidden);

        $shown = $this->get('/leads/duplicate-check?email=mina@example.com')->assertOk()->getContent();
        $this->assertStringContainsString('Mina Mine', $shown);
        $this->assertStringContainsString($mine->reference, $shown);

        $this->assertSame('', trim($this->get('/leads/duplicate-check?email=nobody@example.com')->getContent()));
    }

    public function test_duplicates_block_sales_and_admins_can_override_when_allowed(): void
    {
        Lead::factory()->create(['email' => 'dup@example.com', 'phone' => null, 'assigned_to' => $this->otherSales->id]);

        $this->actingAs($this->sales)->post('/leads', $this->validLead(['email' => 'DUP@example.com', 'phone' => '', 'confirm_duplicate' => '1']))
            ->assertSessionHasErrors('email');
        $this->assertSame(1, Lead::count());
        $this->assertStringNotContainsString('dup@example.com', json_encode(session('duplicate')));

        $this->actingAs($this->admin)->post('/leads', $this->validLead(['email' => 'dup@example.com', 'phone' => '']))->assertSessionHasErrors('email');
        $this->post('/leads', $this->validLead(['email' => 'dup@example.com', 'phone' => '', 'confirm_duplicate' => '1']))->assertSessionHasNoErrors();
        $this->assertSame(2, Lead::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.duplicate_override']);

        Settings::update(['allow_duplicate_override' => false]);
        $this->actingAs($this->manager)->post('/leads', $this->validLead(['email' => 'dup@example.com', 'phone' => '', 'confirm_duplicate' => '1']))
            ->assertSessionHasErrors('email');
        $this->assertSame(2, Lead::count());
    }

    public function test_duplicate_phone_matches_on_digits(): void
    {
        Lead::factory()->create(['email' => null, 'phone' => '+91 98765 43210']);
        $this->actingAs($this->admin)->post('/leads', $this->validLead(['email' => '', 'phone' => '91-98765-43210']))->assertSessionHasErrors('phone');
    }

    // ---- Status changes and milestones ---------------------------------------------------------

    public function test_milestones_are_stamped_only_the_first_time(): void
    {
        $lead = Lead::factory()->create(['lead_status_id' => LeadStatus::where('name', 'New')->value('id')]);
        $changer = app(LeadStatusChanger::class);
        $qualified = LeadStatus::where('name', 'Qualified')->first();
        $new = LeadStatus::where('name', 'New')->first();

        Carbon::setTestNow('2026-10-01 10:00:00');
        $changer->change($lead, $qualified, $this->admin, 'panel');
        $this->assertSame('2026-10-01 10:00:00', $lead->fresh()->qualified_at->toDateTimeString());

        Carbon::setTestNow('2026-10-05 10:00:00');
        $changer->change($lead, $new, $this->admin, 'panel');
        $changer->change($lead->fresh(), $qualified, $this->admin, 'panel');
        Carbon::setTestNow();

        $this->assertSame('2026-10-01 10:00:00', $lead->fresh()->qualified_at->toDateTimeString());
        $this->assertSame(3, Activity::where('lead_id', $lead->id)->where('meta->event', 'status_changed')->count());
        $this->assertSame(3, AuditLog::where('action', 'lead.status_changed')->count());
        $this->assertStringContainsString('Status changed from "Qualified" to "New"', Activity::where('lead_id', $lead->id)->orderBy('id')->skip(1)->first()->description);
    }

    public function test_pipeline_move_returns_204_and_refuses_inactive_status(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->sales->id]);
        $won = LeadStatus::where('name', 'Won')->first();
        $this->actingAs($this->sales);

        $this->withHeader('X-Requested-With', 'fetch')
            ->patch("/leads/{$lead->reference}/status", ['lead_status_id' => $won->id, 'via' => 'kanban'])->assertNoContent();
        $this->flushHeaders();
        $this->assertSame($won->id, $lead->fresh()->lead_status_id);
        $this->assertNotNull($lead->fresh()->won_at);
        $this->assertSame('kanban', AuditLog::where('action', 'lead.status_changed')->latest('id')->first()->meta['via']);

        $won->update(['is_active' => false]);
        $this->patch("/leads/{$lead->reference}/status", ['lead_status_id' => LeadStatus::where('name', 'New')->value('id')])->assertRedirect();
        $this->patch("/leads/{$lead->reference}/status", ['lead_status_id' => $won->id])->assertSessionHasErrors('lead_status_id');
    }

    public function test_pipeline_totals_are_per_currency_and_columns_cap_at_fifty(): void
    {
        $new = LeadStatus::where('name', 'New')->first();
        Lead::factory()->count(55)->create(['lead_status_id' => $new->id, 'deal_value' => 100, 'currency' => 'USD']);
        Lead::factory()->create(['lead_status_id' => $new->id, 'deal_value' => 500000, 'currency' => 'INR']);

        $column = $this->actingAs($this->admin)->get('/pipeline')->viewData('columns')->firstWhere('status.id', $new->id);
        $this->assertSame(56, $column['count']);
        $this->assertCount(50, $column['cards']);
        $this->assertSame(['USD' => 5500, 'INR' => 500000], $column['totals']);

        $more = $this->get('/pipeline?show['.$new->id.']=100')->viewData('columns')->firstWhere('status.id', $new->id);
        $this->assertCount(56, $more['cards']);
    }

    // ---- Bulk ----------------------------------------------------------------------------------

    public function test_bulk_actions_recheck_every_id_against_visibility_and_policy(): void
    {
        ['mine' => $mine, 'unassigned' => $unassigned, 'theirs' => $theirs] = $this->threeLeads();
        $won = LeadStatus::where('name', 'Won')->first();
        $tag = Tag::first();
        $this->actingAs($this->sales);

        $this->post('/leads/bulk', ['ids' => [$mine->id, $unassigned->id, $theirs->id], 'op' => "status:{$won->id}"])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '2 leads moved to Won') && str_contains($m, '1 skipped'));
        $this->assertSame($won->id, $mine->fresh()->lead_status_id);
        $this->assertNotSame($won->id, $theirs->fresh()->lead_status_id);

        // Sales can only bulk-assign to themselves.
        $this->post('/leads/bulk', ['ids' => [$unassigned->id], 'op' => "assign:{$this->otherSales->id}"]);
        $this->assertNull($unassigned->fresh()->assigned_to);
        $this->post('/leads/bulk', ['ids' => [$unassigned->id], 'op' => "assign:{$this->sales->id}"]);
        $this->assertSame($this->sales->id, $unassigned->fresh()->assigned_to);

        $this->post('/leads/bulk', ['ids' => [$mine->id, $theirs->id], 'op' => "tag_add:{$tag->id}"]);
        $this->assertTrue($mine->tags()->whereKey($tag->id)->exists());
        $this->assertFalse($theirs->tags()->whereKey($tag->id)->exists());
        $this->post('/leads/bulk', ['ids' => [$mine->id], 'op' => "tag_remove:{$tag->id}"]);
        $this->assertFalse($mine->tags()->whereKey($tag->id)->exists());

        // Archiving is admin-only.
        $this->post('/leads/bulk', ['ids' => [$mine->id], 'op' => 'archive']);
        $this->assertFalse($mine->fresh()->trashed());
        $this->actingAs($this->admin)->post('/leads/bulk', ['ids' => [$mine->id, $theirs->id], 'op' => 'archive']);
        $this->assertTrue($mine->fresh()->trashed());
        $this->assertSame(2, AuditLog::where('action', 'lead.bulk_action')->latest('id')->first()->meta['count']);
    }

    public function test_bulk_is_capped_at_500_ids(): void
    {
        $this->actingAs($this->admin)->post('/leads/bulk', ['ids' => range(1, 501), 'op' => 'archive'])->assertSessionHasErrors('ids');
    }

    // ---- Archive -------------------------------------------------------------------------------

    public function test_only_admins_archive_restore_and_see_archived_leads(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->sales->id]);

        $this->actingAs($this->sales)->delete("/leads/{$lead->reference}")->assertForbidden();
        $this->actingAs($this->manager)->delete("/leads/{$lead->reference}")->assertForbidden();
        $this->actingAs($this->manager)->get('/leads?archived=1')->assertForbidden();

        $this->actingAs($this->admin)->delete("/leads/{$lead->reference}")->assertRedirect();
        $this->assertTrue($lead->fresh()->trashed());
        $this->assertSame([$lead->id], $this->get('/leads?archived=1')->viewData('leads')->pluck('id')->all());
        $this->assertNotContains($lead->id, $this->get('/leads')->viewData('leads')->pluck('id')->all());
        $this->get("/leads/{$lead->reference}")->assertNotFound();

        $this->post("/leads/{$lead->reference}/restore")->assertRedirect();
        $this->assertFalse($lead->fresh()->trashed());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.archived']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.restored']);
    }

    // ---- Filters -------------------------------------------------------------------------------

    public function test_filters_and_quick_filters(): void
    {
        $this->actingAs($this->admin);
        $usd = Lead::factory()->create(['currency' => 'USD', 'city' => 'Pune', 'priority' => 'urgent', 'assigned_to' => $this->admin->id]);
        $inr = Lead::factory()->create(['currency' => 'INR', 'city' => 'Delhi', 'priority' => 'low', 'assigned_to' => null]);
        $ids = fn (string $q) => $this->get('/leads?'.$q)->assertOk()->viewData('leads')->pluck('id')->all();

        $this->assertSame([$usd->id], $ids('currency=USD'));
        $this->assertSame([$inr->id], $ids('city=delhi'));
        $this->assertSame([$usd->id], $ids('quick=urgent'));
        $this->assertSame([$usd->id], $ids('quick=mine'));
        $this->assertSame([$inr->id], $ids('quick=unassigned'));
        $this->assertSame([$inr->id], $ids('assigned=unassigned'));
        $this->get('/leads?currency=XYZ')->assertSessionHasErrors('currency');
        $this->assertCount(2, $this->get('/leads?sort=deal_value&dir=asc')->viewData('leads'));
    }
}
