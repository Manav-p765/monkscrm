<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard and Reports figures against a 31-lead fixture whose expected numbers were counted by
 * hand (see the table in fixture()). Leads move through statuses with the real LeadStatusChanger,
 * so the milestone stamps come from production code, not from the fixture.
 */
class DataCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $a;

    private User $b;

    /** @var array<int, Lead> fixture row number => lead */
    private array $leads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-10-08 06:30:00'); // 12:00 IST
        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $this->manager = User::factory()->manager()->create(['name' => 'Max Manager']);
        $this->a = User::factory()->sales()->create(['name' => 'Asha Sales']);
        $this->b = User::factory()->sales()->create(['name' => 'Ben Sales']);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     *  #  owner  path (statuses visited, in order)               deal value
     *  1  A      New                                             —
     *  2  A      New                                             ₹50,000
     *  3  A      Contacted                                       $1,000
     *  4  A      Contacted › Qualified                           $2,000
     *  5  A      Contacted › Qualified › Proposal Sent           ₹3,00,000
     *  6  A      Contacted › Qualified › Proposal Sent › Won     $5,000
     *  7  A      Won (straight from New)                         ₹1,00,000
     *  8  A      Contacted › Lost                                $700
     *  9  A      Attempted Contact                               —
     * 10  A      Contacted › Meeting Booked                      ₹80,000
     * 11  B      New                                             $300
     * 12  B      Contacted                                       ₹20,000
     * 13  B      Contacted › Qualified › Won                     $12,000
     * 14  B      Contacted › Qualified › Won                     ₹2,50,000
     * 15  B      Not Interested                                  —
     * 16  B      Contacted › Qualified › Meeting › Proposal      $8,000
     * 17  B      Invalid                                         —
     * 18  B      Follow-Up Required                              $450
     * 19  M      New                                             $900
     * 20  M      Contacted › Qualified › Opportunity             $15,000
     * 21  M      Contacted › Won                                 ₹75,000
     * 22  M      Lost                                            $3,000
     * 23  —      New                                             —
     * 24  —      New                                             ₹10,000
     * 25  —      Contacted                                       $600
     * 26  —      Contacted › Qualified                           ₹40,000
     * 27  A      Opportunity                                     $2,500
     * 28  B      Contacted › Lost › Contacted                    $100
     * 29  A      New                                             $0
     * 30  —      Won                                             $4,000
     * 31  B      Won, created 15 Sep (outside the report range)  $999
     */
    private function fixture(): void
    {
        $rows = [
            1 => ['a', [], null, null], 2 => ['a', [], 'INR', 50000], 3 => ['a', ['Contacted'], 'USD', 1000],
            4 => ['a', ['Contacted', 'Qualified'], 'USD', 2000], 5 => ['a', ['Contacted', 'Qualified', 'Proposal Sent'], 'INR', 300000],
            6 => ['a', ['Contacted', 'Qualified', 'Proposal Sent', 'Won'], 'USD', 5000], 7 => ['a', ['Won'], 'INR', 100000],
            8 => ['a', ['Contacted', 'Lost'], 'USD', 700], 9 => ['a', ['Attempted Contact'], null, null],
            10 => ['a', ['Contacted', 'Meeting Booked'], 'INR', 80000], 11 => ['b', [], 'USD', 300], 12 => ['b', ['Contacted'], 'INR', 20000],
            13 => ['b', ['Contacted', 'Qualified', 'Won'], 'USD', 12000], 14 => ['b', ['Contacted', 'Qualified', 'Won'], 'INR', 250000],
            15 => ['b', ['Not Interested'], null, null], 16 => ['b', ['Contacted', 'Qualified', 'Meeting Booked', 'Proposal Sent'], 'USD', 8000],
            17 => ['b', ['Invalid'], null, null], 18 => ['b', ['Follow-Up Required'], 'USD', 450], 19 => ['manager', [], 'USD', 900],
            20 => ['manager', ['Contacted', 'Qualified', 'Opportunity'], 'USD', 15000], 21 => ['manager', ['Contacted', 'Won'], 'INR', 75000],
            22 => ['manager', ['Lost'], 'USD', 3000], 23 => [null, [], null, null], 24 => [null, [], 'INR', 10000],
            25 => [null, ['Contacted'], 'USD', 600], 26 => [null, ['Contacted', 'Qualified'], 'INR', 40000], 27 => ['a', ['Opportunity'], 'USD', 2500],
            28 => ['b', ['Contacted', 'Lost', 'Contacted'], 'USD', 100], 29 => ['a', [], 'USD', 0], 30 => [null, ['Won'], 'USD', 4000],
            31 => ['b', ['Won'], 'USD', 999],
        ];
        $changer = app(LeadStatusChanger::class);
        $new = LeadStatus::where('name', 'New')->first();
        $source = LeadSource::where('name', 'Referral')->value('id');

        foreach ($rows as $n => [$owner, $path, $currency, $major]) {
            $lead = Lead::factory()->create([
                'assigned_to' => $owner ? $this->{$owner}->id : null, 'lead_status_id' => $new->id, 'lead_source_id' => $source,
                'currency' => $currency ?? 'USD', 'deal_value' => $major === null ? null : $major * 100, 'next_follow_up_at' => null,
                'created_at' => $n === 31 ? Carbon::parse('2026-09-15 05:00:00') : now(),
            ]);
            foreach ($path as $status) {
                $changer->change($lead, LeadStatus::where('name', $status)->first(), $this->admin, 'form');
            }
            $this->leads[$n] = $lead->fresh();
        }

        // Follow-ups. "Today" ends at midnight IST = 18:30 UTC.
        $f = fn (int $lead, User $to, string $due, string $status = 'pending') => FollowUp::forceCreate([
            'lead_id' => $this->leads[$lead]->id, 'assigned_to' => $to->id, 'created_by' => $to->id,
            'type' => 'call', 'due_at' => Carbon::parse($due), 'status' => $status,
        ]);
        $f(3, $this->a, '2026-10-08 05:30:00');                 // overdue (11:00 IST)
        $f(4, $this->a, '2026-10-08 09:30:00');                 // today (15:00 IST)
        $f(5, $this->a, '2026-10-08 18:00:00');                 // today (23:30 IST)
        $f(5, $this->a, '2026-10-08 19:00:00');                 // tomorrow 00:30 IST: NOT today
        $f(11, $this->b, '2026-10-06 06:30:00');                // overdue
        $f(12, $this->b, '2026-10-07 06:30:00', 'completed');   // was due yesterday, done
        $f(25, $this->a, '2026-10-08 06:00:00');                // overdue, on an unassigned lead
    }

    public function test_admin_dashboard_matches_the_hand_count(): void
    {
        $page = $this->actingAs($this->admin)->get('/dashboard')->assertOk();

        // 31 leads; New 7 (1,2,11,19,23,24,29); Won 7 (6,7,13,14,21,30,31); lost-type 4 (8,22,15,17);
        // ever contacted 15; ever qualified 8. Straight-to-Won (7, 30, 31) never counted as contacted.
        $this->assertSame(['total' => 31, 'new' => 7, 'contacted' => 15, 'qualified' => 8, 'won' => 7, 'lost' => 4,
            'due_today' => 2, 'overdue' => 3], $page->viewData('cards'));

        // Won: $5,000 + $12,000 + $4,000 + $999 and ₹1,00,000 + ₹2,50,000 + ₹75,000. Never added together.
        $this->assertSame(['USD' => 2199900, 'INR' => 42500000], $page->viewData('wonValue'));
        $page->assertSee('$21,999 · ₹4,25,000', false);

        // Open statuses: $30,850 and ₹5,00,000.
        $this->assertSame(['USD' => 3085000, 'INR' => 50000000], $page->viewData('activeTotals'));
        $strip = collect($page->viewData('pipeline'))->mapWithKeys(fn ($r) => [$r['status']->name => [$r['count'], $r['totals']]]);
        $this->assertSame([7, ['USD' => 120000, 'INR' => 6000000]], $strip['New']);
        $this->assertSame([4, ['USD' => 170000, 'INR' => 2000000]], $strip['Contacted']);
        $this->assertSame([2, ['USD' => 800000, 'INR' => 30000000]], $strip['Proposal Sent']);
        $this->assertSame([2, ['USD' => 1750000]], $strip['Opportunity']);
        $this->assertSame(20, $strip->sum(fn ($r) => $r[0])); // 7 + 1 + 4 + 1 + 2 + 1 + 2 + 2 leads in open statuses
    }

    public function test_sales_dashboard_matches_the_hand_count_with_and_without_unassigned_leads(): void
    {
        // Asha sees her 12 leads + 5 unassigned (23–26, 30).
        $page = $this->actingAs($this->a)->get('/dashboard')->assertOk();
        $this->assertSame(['total' => 17, 'new' => 5, 'contacted' => 8, 'qualified' => 4, 'won' => 3, 'lost' => 1,
            'due_today' => 2, 'overdue' => 2], $page->viewData('cards'));
        $this->assertSame(['USD' => 900000, 'INR' => 10000000], $page->viewData('wonValue'));

        Settings::update(['sales_can_view_unassigned' => false]);
        $page = $this->actingAs($this->a)->get('/dashboard')->assertOk();
        $this->assertSame(['total' => 12, 'new' => 3, 'contacted' => 6, 'qualified' => 3, 'won' => 2, 'lost' => 1,
            'due_today' => 2, 'overdue' => 1], $page->viewData('cards'));
        $this->assertSame(['USD' => 500000, 'INR' => 10000000], $page->viewData('wonValue'));

        // Ben: 10 leads (11–18, 28, 31); his follow-ups only.
        $page = $this->actingAs($this->b)->get('/dashboard')->assertOk();
        $this->assertSame(['total' => 10, 'new' => 1, 'contacted' => 5, 'qualified' => 3, 'won' => 3, 'lost' => 2,
            'due_today' => 0, 'overdue' => 1], $page->viewData('cards'));
        $this->assertSame(['USD' => 1299900, 'INR' => 25000000], $page->viewData('wonValue'));
    }

    public function test_reports_match_the_hand_count_and_respect_the_date_range(): void
    {
        $page = $this->actingAs($this->manager)->get('/reports?from=2026-10-01&to=2026-10-08')->assertOk();

        // Lead 31 (15 Sep) is outside the range. The funnel is cumulative (a lead counts at every step up to the
        // furthest it reached): furthest step none 13, Contacted 5, Qualified 3, Meeting 1, Proposal 2, Won 6.
        $this->assertSame([30, 17, 12, 9, 8, 6], array_column($page->viewData('funnel'), 'count'));
        $this->assertSame(['USD' => 2100000, 'INR' => 42500000], $page->viewData('wonValue'));
        // Open statuses: $30,850 and ₹5,00,000. Average won deal: ($5,000 + $12,000 + $4,000) / 3 and
        // (₹1,00,000 + ₹2,50,000 + ₹75,000) / 3 = ₹1,41,666.67.
        $this->assertSame(['USD' => 3085000, 'INR' => 50000000], $page->viewData('activeValue'));
        $this->assertSame(['USD' => 700000, 'INR' => 14166667], $page->viewData('averageDealSize'));
        $page->assertSee('$7,000 · ₹1,41,666.67', false);
        $this->assertSame(['Asha Sales' => 12, 'Ben Sales' => 9, 'Unassigned' => 5, 'Max Manager' => 4],
            $page->viewData('byAssignee')->pluck('count', 'label')->all());

        $card = fn (User $u) => $page->viewData('scorecard')->firstWhere('user.id', $u->id);
        $this->assertSame([12, 6, 3, 2], [$card($this->a)['assigned'], $card($this->a)['contacted'], $card($this->a)['qualified'], $card($this->a)['won']]);
        $this->assertSame(['USD' => 500000, 'INR' => 10000000], $card($this->a)['won_value']);
        $this->assertSame([9, 5, 3, 2], [$card($this->b)['assigned'], $card($this->b)['contacted'], $card($this->b)['qualified'], $card($this->b)['won']]);
        $this->assertSame(['USD' => 1200000, 'INR' => 25000000], $card($this->b)['won_value']);

        // Follow-ups that came due in range: 4 (3, 11, 12, 25); 1 completed. Asha 0 of 2, Ben 1 of 2.
        $this->assertSame(25, $page->viewData('completion'));
        $this->assertSame(0, $card($this->a)['follow_up_rate']);
        $this->assertSame(50, $card($this->b)['follow_up_rate']);

        // A range that only holds lead 31.
        $sept = $this->get('/reports?from=2026-09-15&to=2026-09-15')->assertOk();
        $this->assertSame([1, 1, 1, 1, 1, 1], array_column($sept->viewData('funnel'), 'count'));
        $this->assertSame(['USD' => 99900], $sept->viewData('wonValue'));
    }

    public function test_report_csv_figures_match_the_page_and_never_add_currencies(): void
    {
        $csv = $this->actingAs($this->admin)->get('/reports/export?from=2026-10-01&to=2026-10-08')->assertOk()->streamedContent();

        $this->assertStringContainsString("Created,30\n", $csv);
        $this->assertStringContainsString("Won,6\n", $csv);
        $this->assertStringContainsString("\nNew,7,\"\$1,200 · ₹60,000\"\n", $csv);
        $this->assertStringContainsString("\nWon,6,\"\$21,000 · ₹4,25,000\"\n", $csv);
        $this->assertStringContainsString('"Asha Sales",,12,6,3,2,"$5,000 · ₹1,00,000"', $csv);
    }

    public function test_lead_export_shows_times_in_ist(): void
    {
        $lead = $this->leads[3];
        $lead->forceFill(['created_at' => Carbon::parse('2026-10-07 20:00:00'), 'last_contacted_at' => Carbon::parse('2026-10-08 05:00:00')])->saveQuietly();

        $csv = $this->actingAs($this->admin)
            ->get('/leads-export?q='.$lead->reference.'&columns[]=reference&columns[]=created_at&columns[]=last_contacted_at&columns[]=deal_value&columns[]=currency')
            ->assertOk()->streamedContent();

        // 20:00 UTC on 7 Oct is 1:30 am on 8 Oct in India; 05:00 UTC is 10:30 am.
        $this->assertStringContainsString("{$lead->reference},1000,USD,\"8 Oct 2026, 10:30 am\",\"8 Oct 2026, 1:30 am\"", $csv);
    }

    public function test_milestones_are_never_overwritten_by_later_moves_activities_or_imports(): void
    {
        $lead = $this->leads[28]; // Contacted › Lost › Contacted
        $stamps = $lead->only(['first_contacted_at', 'lost_at']);
        $this->assertNotNull($stamps['lost_at']);

        Carbon::setTestNow('2026-10-09 06:30:00');
        $changer = app(LeadStatusChanger::class);
        foreach (['Lost', 'Qualified', 'Contacted'] as $status) {
            $changer->change($lead, LeadStatus::where('name', $status)->first(), $this->admin, 'kanban');
        }
        $this->actingAs($this->b)->post("/leads/{$lead->reference}/activities", ['type' => 'call', 'description' => 'Called again'])->assertSessionHasNoErrors();

        $fresh = $lead->fresh();
        $this->assertEquals($stamps['first_contacted_at'], $fresh->first_contacted_at);
        $this->assertEquals($stamps['lost_at'], $fresh->lost_at);
        $this->assertEquals(Carbon::parse('2026-10-09 06:30:00'), $fresh->qualified_at); // first time: stamped
    }

    public function test_import_never_puts_a_lead_in_a_turned_off_status(): void
    {
        LeadStatus::where('name', 'Opportunity')->update(['is_active' => false]);
        $importer = app(\App\Services\Imports\LeadImporter::class);
        $lookups = (fn () => $this->lookups())->call($importer);

        foreach (['default', 'create'] as $choice) {
            [$fields] = $importer->mapRow(['S' => 'opportunity', 'N' => 'Ann', 'E' => 'ann@example.com'],
                ['S' => 'status', 'N' => 'first_name', 'E' => 'email'], ['unknown' => ['status' => $choice]], $lookups, $this->admin);
            $this->assertSame((int) Settings::get('default_status_id'), $fields['status_id'], $choice);
        }
        $this->assertSame(1, LeadStatus::whereRaw('lower(name) = ?', ['opportunity'])->count());
    }

    public function test_follow_ups_page_uses_ist_days(): void
    {
        $page = $this->actingAs($this->a)->get('/follow-ups?tab=today')->assertOk();
        // Due 15:00 and 23:30 IST are today; 00:30 IST tomorrow is upcoming.
        $this->assertSame(['pending' => 5, 'overdue' => 2, 'today' => 2, 'upcoming' => 1, 'completed' => 0], $page->viewData('counts')->all());
    }
}
