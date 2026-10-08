<?php

namespace App\Support;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The header alerts bell, computed per request (no cron): my overdue follow-ups, my follow-ups due
 * today (IST), and leads newly assigned to me since I last clicked "Mark all as seen"
 * (users.alerts_seen_at). Everything respects lead visibility.
 */
class Alerts
{
    public const LIST_LIMIT = 8;

    /**
     * @return array{overdue: Collection, today: Collection, assigned: Collection, counts: array{overdue: int, today: int, assigned: int}, total: int}
     */
    public static function for(User $user): array
    {
        $now = now();
        $endOfToday = Dates::endOfToday();

        $mine = fn () => FollowUp::query()->visibleTo($user)
            ->where('assigned_to', $user->id)->where('status', FollowUpStatus::Pending);

        $overdue = $mine()->where('due_at', '<', $now);
        $today = $mine()->where('due_at', '>=', $now)->where('due_at', '<', $endOfToday);

        // Leads assigned to me (by someone else) after I last marked alerts as seen.
        $assigned = Lead::query()->visibleTo($user)->where('assigned_to', $user->id)
            ->whereHas('activities', fn ($q) => $q->where('meta->event', 'assigned')
                ->where('created_at', '>', $user->alerts_seen_at ?? $user->created_at)
                ->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $user->id)));

        $counts = [
            'overdue' => (clone $overdue)->count(),
            'today' => (clone $today)->count(),
            'assigned' => (clone $assigned)->count(),
        ];

        return [
            'overdue' => $overdue->with('lead:id,reference,first_name,last_name,company')->orderBy('due_at')->limit(self::LIST_LIMIT)->get(),
            'today' => $today->with('lead:id,reference,first_name,last_name,company')->orderBy('due_at')->limit(self::LIST_LIMIT)->get(),
            'assigned' => $assigned->latest('updated_at')->limit(self::LIST_LIMIT)->get(['id', 'reference', 'first_name', 'last_name', 'company', 'updated_at']),
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }
}
