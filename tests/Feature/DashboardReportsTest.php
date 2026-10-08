<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Team;
use App\Models\User;
use App\Support\Csv;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sales;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Settings::update(['sales_can_view_unassigned' => false]);
        Carbon::setTestNow('2026-10-08 06:30:00'); // 12:00 IST
        $team = Team::create(['name' => 'North']);
        $this->admin = User::factory()->admin()->create();
        $this->sales = User::factory()->sales()->create(['team_id' => $team->id, 'name' => 'Sam Sales']);
        $this->other = User::factory()->sales()->create(['name' => 'Olive Other']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function statusId(string $name): int
    {
        return LeadStatus::where('name', $name)->value('id');
    }

    /** Fixture: Sam has 4 leads (1 new, 1 qualified, 2 won: $100 + ₹5,000), Olive has 1 lost lead. */
    private function fixture(): void
    {
        $base = ['lead_source_id' => LeadSource::where('name', 'Referral')->value('id'), 'next_follow_up_at' => null];
        Lead::factory()->create([...$base, 'assigned_to' => $this->sales->id, 'lead_status_id' => $this->statusId('New'), 'deal_value' => null]);
        Lead::factory()->create([...$base, 'assigned_to' => $this->sales->id, 'lead_status_id' => $this->statusId('Qualified'),
            'first_contacted_at' => now()->subHours(10), 'qualified_at' => now()->subHours(5), 'deal_value' => 9900, 'currency' => 'USD', 'created_at' => now()->subHours(14)]);
        Lead::factory()->create([...$base, 'assigned_to' => $this->sales->id, 'lead_status_id' => $this->statusId('Won'),
            'first_contacted_at' => now()->subHours(2), 'qualified_at' => now()->subHour(), 'won_at' => now(), 'deal_value' => 10000, 'currency' => 'USD', 'created_at' => now()->subHours(6)]);
        Lead::factory()->create([...$base, 'assigned_to' => $this->sales->id, 'lead_status_id' => $this->statusId('Won'),
            'first_contacted_at' => now(), 'won_at' => now(), 'deal_value' => 500000, 'currency' => 'INR', 'created_at' => now()]);
        Lead::factory()->create([...$base, 'assigned_to' => $this->other->id, 'lead_status_id' => $this->statusId('Lost'), 'lost_at' => now(), 'deal_value' => 77700, 'currency' => 'USD']);

        $lead = Lead::where('assigned_to', $this->sales->id)->first();
        $make = fn ($due, $status) => FollowUp::forceCreate(['lead_id' => $lead->id, 'assigned_to' => $this->sales->id, 'created_by' => $this->sales->id, 'type' => 'call', 'due_at' => $due, 'status' => $status]);
        $make(now()->subHour(), 'pending');            // overdue
        $make(now()->addHours(3), 'pending');          // due today (15:00 IST)
        $make(now()->subDays(2), 'completed');         // was due, done
        $make(now()->subDays(3), 'cancelled');         // ignored by completion rate
    }

    public function test_dashboard_numbers_respect_visibility_and_never_sum_currencies(): void
    {
        $this->fixture();

        $admin = $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $this->assertSame(['total' => 5, 'new' => 1, 'contacted' => 3, 'qualified' => 2, 'won' => 2, 'lost' => 1, 'due_today' => 1, 'overdue' => 1], $admin->viewData('cards'));
        $this->assertSame(['USD' => 10000, 'INR' => 500000], $admin->viewData('wonValue'));
        $admin->assertSee('$100 · ₹5,000', false);

        // Sales sees only their own leads (unassigned/other leads hidden).
        $sales = $this->actingAs($this->sales)->get('/dashboard')->assertOk();
        $this->assertSame(4, $sales->viewData('cards')['total']);
        $this->assertSame(0, $sales->viewData('cards')['lost']);
        $this->assertNotContains('Olive Other', $sales->viewData('recent')->pluck('user.name')->all());
    }

    public function test_sales_cannot_open_or_export_reports(): void
    {
        $this->actingAs($this->sales)->get('/reports')->assertForbidden();
        $this->actingAs($this->sales)->get('/reports/export')->assertForbidden();
        $this->actingAs(User::factory()->manager()->create())->get('/reports')->assertOk();
    }

    public function test_report_numbers_match_the_fixture(): void
    {
        $this->fixture();
        $page = $this->actingAs($this->admin)->get('/reports?from=2026-10-01&to=2026-10-08')->assertOk();

        $this->assertSame([5, 3, 2, 0, 0, 2], array_column($page->viewData('funnel'), 'count'));
        $this->assertSame(['Referral' => 5], $page->viewData('bySource')->pluck('count', 'label')->all());
        $this->assertSame(['Sam Sales' => 4, 'Olive Other' => 1], $page->viewData('byAssignee')->pluck('count', 'label')->all());
        $this->assertEqualsCanonicalizing(['North' => 4, 'No team' => 1], $page->viewData('byTeam')->pluck('count', 'label')->all());

        $sam = $page->viewData('scorecard')->firstWhere('user.id', $this->sales->id);
        $this->assertSame([4, 3, 2, 2], [$sam['assigned'], $sam['contacted'], $sam['qualified'], $sam['won']]);
        $this->assertSame(['USD' => 10000, 'INR' => 500000], $sam['won_value']);
        $this->assertEqualsWithDelta(2.7, $sam['avg_hours_to_contact'], 0.05); // (4 + 4 + 0) / 3 = 2.67 hours
        $this->assertSame(50, $sam['follow_up_rate']); // 1 completed of 2 due (overdue + completed); cancelled ignored
        $this->assertSame(50, $page->viewData('completion'));

        // A range before the leads were created shows nothing.
        $empty = $this->get('/reports?from=2026-01-01&to=2026-01-31')->assertOk();
        $this->assertSame(0, $empty->viewData('cards')['total']);
    }

    public function test_report_export_is_audited_and_escapes_formulas(): void
    {
        $this->fixture();
        $this->sales->update(['name' => '=HYPERLINK("http://evil")']);

        $csv = $this->actingAs($this->admin)->get('/reports/export?from=2026-10-01&to=2026-10-08')->assertOk()->streamedContent();

        $this->assertStringContainsString('Scorecard', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(",=HYPERLINK", $csv);
        $this->assertStringContainsString('$100 · ₹5,000', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);

        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx"] as $danger) {
            $this->assertSame("'".$danger, Csv::cell($danger));
        }
        $this->assertSame('Plain', Csv::cell('Plain'));
    }
}
