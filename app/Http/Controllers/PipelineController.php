<?php

namespace App\Http\Controllers;

use App\Enums\LeadPriority;
use App\Http\Requests\LeadListRequest;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Support\LeadFilters;
use App\Support\Money;
use Illuminate\View\View;

/**
 * Kanban (prototype KanbanBoard): one column per active status, in order, with a count and
 * per-currency totals (never summed across currencies). Same filters as the list. Max 50 cards per
 * column, then "Show more" (?show[STATUS_ID]=100). Moves go through LeadController::updateStatus.
 */
class PipelineController extends Controller
{
    public const PER_COLUMN = 50;

    public function __invoke(LeadListRequest $request): View
    {
        $user = $request->user();
        $filters = $request->filters();
        $show = collect((array) $request->query('show'))->map(fn ($n) => min(max((int) $n, self::PER_COLUMN), 1000));

        $base = function () use ($user, $filters) {
            $q = Lead::query()->visibleTo($user);
            LeadFilters::apply($q, collect($filters)->except(['status', 'sort', 'dir'])->all(), $user);

            return $q->reorder();
        };

        $statuses = LeadStatus::query()->active()->ordered()->get();
        $counts = $base()->toBase()->selectRaw('lead_status_id, count(*) as total')->groupBy('lead_status_id')->pluck('total', 'lead_status_id');

        $columns = $statuses->map(function (LeadStatus $status) use ($base, $counts, $show) {
            $limit = (int) ($show[$status->id] ?? self::PER_COLUMN);
            $cards = $base()->where('lead_status_id', $status->id)
                ->with(['assignee:id,name', 'tags:id,name,color'])
                ->orderByDesc('last_activity_at')->orderByDesc('id')
                ->limit($limit)->get();

            return [
                'status' => $status,
                'count' => (int) ($counts[$status->id] ?? 0),
                'totals' => Money::totals($base()->where('lead_status_id', $status->id)),
                'cards' => $cards,
                'limit' => $limit,
            ];
        });

        return view('pipeline.index', [
            'columns' => $columns,
            'statuses' => $statuses,
            'lookups' => [
                'statuses' => $statuses,
                'sources' => LeadSource::query()->ordered()->get(),
                'tags' => Tag::query()->orderBy('name')->get(),
                'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'priorities' => LeadPriority::cases(),
            ],
            'filters' => $filters,
            'filtersActive' => LeadFilters::active(collect($filters)->except(['status'])->all()),
            'total' => $columns->sum('count'),
        ]);
    }
}
