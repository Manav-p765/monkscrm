<?php

namespace App\Support;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadNote;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A lead's timeline: logged activities, system events (status, assignment, follow-ups…) and notes,
 * merged newest first, filterable by type, paginated.
 */
class LeadTimeline
{
    public const FILTERS = [
        'all' => 'All', 'call' => 'Calls', 'email' => 'Emails', 'meeting' => 'Meetings',
        'note' => 'Notes', 'follow_up' => 'Follow-Ups', 'system' => 'Changes',
    ];

    public const PER_PAGE = 20;

    public static function for(Lead $lead, string $filter = 'all', int $page = 1): LengthAwarePaginator
    {
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';

        $activities = $lead->activities()->with('user:id,name')->get()
            ->map(fn (Activity $a) => [
                'kind' => self::kindOf($a),
                'at' => $a->occurred_at,
                'user' => $a->user?->name,
                'text' => $a->description,
                'outcome' => $a->outcome,
                'event' => $a->meta['event'] ?? $a->type->value,
            ]);

        $notes = $lead->notes()->with('user:id,name')->get()->map(fn (LeadNote $n) => [
            'kind' => 'note', 'at' => $n->created_at, 'user' => $n->user?->name,
            'text' => $n->body, 'outcome' => null, 'event' => 'note',
        ]);

        $items = $activities->concat($notes)
            ->when($filter !== 'all', fn ($c) => $c->where('kind', $filter))
            ->sortByDesc(fn ($i) => $i['at']->getTimestamp())
            ->values();

        return new LengthAwarePaginator(
            $items->forPage($page, self::PER_PAGE)->values(), $items->count(), self::PER_PAGE, $page,
            ['pageName' => 'tl_page', 'path' => route('leads.show', $lead), 'query' => ['tab' => 'timeline', 'tl' => $filter]]
        );
    }

    private static function kindOf(Activity $activity): string
    {
        $event = $activity->meta['event'] ?? null;
        if (is_string($event) && str_starts_with($event, 'follow_up')) {
            return 'follow_up';
        }

        return match ($activity->type) {
            ActivityType::Call => 'call',
            ActivityType::Email => 'email',
            ActivityType::Meeting => 'meeting',
            ActivityType::FollowUp => 'follow_up',
            ActivityType::Note => 'note',
            ActivityType::System => 'system',
            default => 'system',
        };
    }
}
