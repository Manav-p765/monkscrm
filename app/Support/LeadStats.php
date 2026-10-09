<?php

namespace App\Support;

use App\Enums\FollowUpStatus;
use App\Enums\LeadStatusType;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard and Reports numbers: a few grouped queries, always on Lead::visibleTo($user).
 * Money is grouped per currency and never summed across currencies.
 */
class LeadStats
{
    public function __construct(private User $user, private ?CarbonInterface $from = null, private ?CarbonInterface $to = null) {}

    /** Visible leads (created in the date range, if any). */
    public function leads(): Builder
    {
        return Lead::query()->visibleTo($this->user)
            ->when($this->from, fn ($q) => $q->where('leads.created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('leads.created_at', '<', $this->to));
    }

    /**
     * One grouped query: per status, how many leads and how many reached each milestone.
     *
     * @return Collection<int, object{lead_status_id: int, total: int, contacted: int, qualified: int, meeting: int, proposal: int, won: int, lost: int}>
     */
    public function byStatus(): Collection
    {
        return $this->leads()->toBase()
            ->selectRaw('lead_status_id, count(*) as total, count(first_contacted_at) as contacted, count(qualified_at) as qualified,
                count(meeting_booked_at) as meeting, count(proposal_sent_at) as proposal, count(won_at) as won, count(lost_at) as lost')
            ->groupBy('lead_status_id')->get()->keyBy('lead_status_id');
    }

    /** @return array<string, int> */
    public function cards(): array
    {
        $rows = $this->byStatus();
        $types = LeadStatus::query()->pluck('type', 'id');
        $sumWhere = fn (LeadStatusType $type) => $rows->filter(fn ($r) => ($types[$r->lead_status_id] ?? null) === $type)->sum('total');
        $default = (int) Settings::get('default_status_id');

        $now = now();
        $endOfToday = Dates::endOfToday();
        $followUps = $this->followUps()->where('status', FollowUpStatus::Pending)->toBase()
            ->selectRaw('sum(case when due_at < ? then 1 else 0 end) as overdue, sum(case when due_at >= ? and due_at < ? then 1 else 0 end) as today', [$now, $now, $endOfToday])
            ->first();

        return [
            'total' => (int) $rows->sum('total'),
            'new' => (int) ($rows[$default]->total ?? 0),
            'contacted' => (int) $rows->sum('contacted'),
            'qualified' => (int) $rows->sum('qualified'),
            'won' => (int) $sumWhere(LeadStatusType::Won),
            'lost' => (int) $sumWhere(LeadStatusType::Lost),
            'due_today' => (int) ($followUps->today ?? 0),
            'overdue' => (int) ($followUps->overdue ?? 0),
        ];
    }

    /** Pending follow-ups on visible leads (sales: their own). */
    public function followUps(): Builder
    {
        return FollowUp::query()->visibleTo($this->user)
            ->when(! Permissions::allows($this->user, 'assign-leads'), fn ($q) => $q->where('assigned_to', $this->user->id));
    }

    /** @return array<string, int> currency => minor units for leads in a Won status */
    public function wonValue(): array
    {
        return Money::totals($this->leads()->whereIn('lead_status_id', LeadStatus::query()->where('type', LeadStatusType::Won)->select('id')));
    }

    /** @return array<string, int> currency => minor units for leads in an Open status (the active pipeline) */
    public function activeValue(): array
    {
        return Money::totals($this->leads()->whereIn('lead_status_id', LeadStatus::query()->where('type', LeadStatusType::Open)->select('id')));
    }

    /** @return array<string, int> currency => average deal value (minor units) of won leads that have one */
    public function averageDealSize(): array
    {
        $averages = $this->leads()->toBase()->whereNotNull('deal_value')
            ->whereIn('lead_status_id', LeadStatus::query()->where('type', LeadStatusType::Won)->select('id'))
            ->selectRaw('currency, avg(deal_value) as average')->groupBy('currency')->pluck('average', 'currency')
            ->map(fn ($avg) => (int) round((float) $avg))->all();

        return Money::totals(null, $averages);
    }

    /** Pipeline strip / report rows: active statuses with count and per-currency totals (2 queries). */
    public function pipeline(): Collection
    {
        $counts = $this->leads()->toBase()->selectRaw('lead_status_id, count(*) as total')->groupBy('lead_status_id')->pluck('total', 'lead_status_id');
        $sums = $this->leads()->toBase()->whereNotNull('deal_value')
            ->selectRaw('lead_status_id, currency, sum(deal_value) as total')->groupBy('lead_status_id', 'currency')->get()
            ->groupBy('lead_status_id');

        return LeadStatus::query()->ordered()->get()->map(fn (LeadStatus $s) => [
            'status' => $s,
            'count' => (int) ($counts[$s->id] ?? 0),
            'totals' => Money::totals(null, ($sums[$s->id] ?? collect())->pluck('total', 'currency')->all()),
        ])->filter(fn ($row) => $row['status']->is_active || $row['count'] > 0)->values();
    }

    /** @return Collection<int, array{label: string, count: int}> */
    public function bySource(): Collection
    {
        $counts = $this->leads()->toBase()->selectRaw('lead_source_id, count(*) as total')->groupBy('lead_source_id')->pluck('total', 'lead_source_id');
        $names = LeadSource::query()->pluck('name', 'id');

        return $counts->map(fn ($n, $id) => ['label' => $names[$id] ?? 'No source', 'count' => (int) $n])->sortByDesc('count')->values();
    }

    /** @return Collection<int, array{label: string, count: int}> */
    public function byAssignee(): Collection
    {
        $counts = $this->leads()->toBase()->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $names = User::query()->pluck('name', 'id');

        return $counts->map(fn ($n, $id) => ['label' => $id ? ($names[$id] ?? 'Former user') : 'Unassigned', 'count' => (int) $n])->sortByDesc('count')->values();
    }

    /** @return Collection<int, array{label: string, count: int}> */
    public function byTeam(): Collection
    {
        $counts = $this->leads()->leftJoin('users', 'users.id', '=', 'leads.assigned_to')->toBase()
            ->selectRaw('users.team_id as team_id, count(*) as total')->groupBy('users.team_id')->pluck('total', 'team_id');
        $names = Team::query()->pluck('name', 'id');

        return $counts->map(fn ($n, $id) => ['label' => $id ? ($names[$id] ?? 'Former team') : 'No team', 'count' => (int) $n])->sortByDesc('count')->values();
    }

    /** Funnel from the milestone timestamps (leads created in range that ever reached each step). */
    public function funnel(): array
    {
        $r = $this->leads()->toBase()->selectRaw('count(*) as created, count(first_contacted_at) as contacted, count(qualified_at) as qualified,
            count(meeting_booked_at) as meeting, count(proposal_sent_at) as proposal, count(won_at) as won')->first();

        return [
            ['label' => 'Created', 'count' => (int) $r->created],
            ['label' => 'Contacted', 'count' => (int) $r->contacted],
            ['label' => 'Qualified', 'count' => (int) $r->qualified],
            ['label' => 'Meeting Booked', 'count' => (int) $r->meeting],
            ['label' => 'Proposal Sent', 'count' => (int) $r->proposal],
            ['label' => 'Won', 'count' => (int) $r->won],
        ];
    }

    /**
     * Sales scorecard per assignee: assigned, contacted, qualified, won, won value per currency,
     * average hours from creation to first contact.
     */
    public function scorecard(): Collection
    {
        $hours = DB::getDriverName() === 'sqlite'
            ? '(julianday(first_contacted_at) - julianday(leads.created_at)) * 24'
            : 'extract(epoch from (first_contacted_at - leads.created_at)) / 3600';

        $rows = $this->leads()->whereNotNull('assigned_to')->toBase()
            ->selectRaw("assigned_to, count(*) as assigned, count(first_contacted_at) as contacted, count(qualified_at) as qualified,
                count(won_at) as won, avg(case when first_contacted_at is not null then {$hours} end) as avg_hours")
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $won = $this->leads()->whereNotNull('assigned_to')->whereNotNull('deal_value')
            ->whereIn('lead_status_id', LeadStatus::query()->where('type', LeadStatusType::Won)->select('id'))
            ->toBase()->selectRaw('assigned_to, currency, sum(deal_value) as total')->groupBy('assigned_to', 'currency')->get()->groupBy('assigned_to');

        $completion = $this->followUpCompletionByUser();

        return User::query()->with('team:id,name')->whereIn('id', $rows->keys())->orderBy('name')->get()
            ->map(fn (User $u) => [
                'user' => $u,
                'assigned' => (int) $rows[$u->id]->assigned,
                'contacted' => (int) $rows[$u->id]->contacted,
                'qualified' => (int) $rows[$u->id]->qualified,
                'won' => (int) $rows[$u->id]->won,
                'won_value' => Money::totals(null, ($won[$u->id] ?? collect())->pluck('total', 'currency')->all()),
                'avg_hours_to_contact' => $rows[$u->id]->avg_hours !== null ? round((float) $rows[$u->id]->avg_hours, 1) : null,
                'follow_up_rate' => $completion[$u->id] ?? null,
            ]);
    }

    /**
     * Follow-up completion rate: of follow-ups that were due in the range (and are no longer
     * cancelled), the share completed. Null when nothing was due.
     */
    public function followUpCompletion(): ?int
    {
        $r = $this->dueFollowUps()->toBase()->selectRaw('count(*) as due, sum(case when status = ? then 1 else 0 end) as done', [FollowUpStatus::Completed->value])->first();

        return (int) $r->due > 0 ? (int) round(100 * (int) $r->done / (int) $r->due) : null;
    }

    /** @return array<int, int> user id => % */
    private function followUpCompletionByUser(): array
    {
        return $this->dueFollowUps()->toBase()
            ->selectRaw('assigned_to, count(*) as due, sum(case when status = ? then 1 else 0 end) as done', [FollowUpStatus::Completed->value])
            ->groupBy('assigned_to')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->assigned_to => (int) round(100 * (int) $r->done / max(1, (int) $r->due))])->all();
    }

    private function dueFollowUps(): Builder
    {
        return FollowUp::query()->visibleTo($this->user)
            ->where('status', '!=', FollowUpStatus::Cancelled)
            ->where('due_at', '<', min(now(), $this->to ?? now()))
            ->when($this->from, fn ($q) => $q->where('due_at', '>=', $this->from));
    }

    /** Recent activity on visible leads (dashboard feed). */
    public function recentActivity(int $limit = 6): Collection
    {
        return Activity::query()->with(['user:id,name', 'lead:id,reference,first_name,last_name,company'])
            ->whereHas('lead', fn ($q) => $q->visibleTo($this->user))
            ->latest('occurred_at')->latest('id')->limit($limit)->get();
    }
}
