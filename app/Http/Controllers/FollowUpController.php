<?php

namespace App\Http\Controllers;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;
use App\Support\Dates;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\FollowUpListRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Follow-ups page (prototype TasksFollowUpsView): Overdue, Today, Upcoming, Completed.
 * Sales see their own; admins and managers see everyone's, with an assignee filter.
 * Overdue is computed at query time (pending and past due), never stored.
 */
class FollowUpController extends Controller
{
    public const TABS = ['pending' => 'All Pending', 'overdue' => 'Overdue', 'today' => 'Due Today', 'upcoming' => 'Upcoming', 'completed' => 'Completed History'];

    public function index(FollowUpListRequest $request): View
    {
        $user = $request->user();
        $canSeeAll = Permissions::allows($user, 'assign-leads');
        $filters = $request->validated();
        $tab = $filters['tab'] ?? 'pending';

        $now = now();
        $endOfToday = Dates::endOfToday();

        $base = function () use ($user, $canSeeAll, $filters) {
            return FollowUp::query()->visibleTo($user)
                ->when(! $canSeeAll, fn ($q) => $q->where('assigned_to', $user->id))
                ->when($canSeeAll && ! empty($filters['assigned']), fn ($q) => $q->where('assigned_to', $filters['assigned']));
        };
        $scoped = fn (string $t) => match ($t) {
            'pending' => $base()->where('status', FollowUpStatus::Pending),
            'overdue' => $base()->where('status', FollowUpStatus::Pending)->where('due_at', '<', $now),
            'today' => $base()->where('status', FollowUpStatus::Pending)->where('due_at', '>=', $now)->where('due_at', '<', $endOfToday),
            'upcoming' => $base()->where('status', FollowUpStatus::Pending)->where('due_at', '>=', $endOfToday),
            'completed' => $base()->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled]),
        };

        $query = $scoped($tab)->with(['lead:id,reference,first_name,last_name,company', 'assignee:id,name']);
        $tab === 'completed' ? $query->latest('completed_at')->latest('updated_at') : $query->orderBy('due_at');

        return view('follow-ups.index', [
            'followUps' => $query->paginate(25)->withQueryString(),
            'tab' => $tab,
            'counts' => collect(array_keys(self::TABS))->mapWithKeys(fn ($t) => [$t => $scoped($t)->count()]),
            'canSeeAll' => $canSeeAll,
            'filters' => $filters,
            'users' => $canSeeAll ? User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    /** Alerts bell: "Mark all as seen" (newly assigned leads stop counting). */
    public function markAlertsSeen(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['alerts_seen_at' => now()])->save();

        return back()->with('status', 'Alerts marked as seen');
    }
}
