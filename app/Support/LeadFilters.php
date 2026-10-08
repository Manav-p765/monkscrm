<?php

namespace App\Support;

use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Models\Concerns\Searchable;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Lead list filters (prototype LeadListView + our extra filters), shared by the list, the pipeline
 * and exports so they always agree. Filter state lives in the URL query string.
 * Callers must start from Lead::visibleTo($user).
 */
class LeadFilters
{
    public const QUICK = [
        'all' => 'All Leads',
        'mine' => 'My Assigned Leads',
        'due' => 'Follow-Up Due / Overdue',
        'unassigned' => 'Unassigned Leads',
        'urgent' => 'Urgent & High Priority',
    ];

    public const SORTS = ['name', 'status', 'priority', 'next_follow_up', 'deal_value', 'created', 'last_activity'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'quick' => ['nullable', Rule::in(array_keys(self::QUICK))],
            'status' => ['nullable', 'integer', Rule::exists('lead_statuses', 'id')],
            'assigned' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'unassigned' && ! ctype_digit((string) $value)) {
                    $fail('Choose someone from the list.');
                }
            }],
            'source' => ['nullable', 'integer', Rule::exists('lead_sources', 'id')],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'tag' => ['nullable', 'integer', Rule::exists('tags', 'id')],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'follow_up_from' => ['nullable', 'date_format:Y-m-d'],
            'follow_up_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:follow_up_from'],
            'activity_from' => ['nullable', 'date_format:Y-m-d'],
            'activity_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:activity_from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** Filters that live behind the "More" toggle (open it when any is set). */
    public const ADVANCED = ['source', 'priority', 'tag', 'currency', 'city', 'state', 'country',
        'created_from', 'created_to', 'follow_up_from', 'follow_up_to', 'activity_from', 'activity_to'];

    /**
     * @param  array<string, mixed>  $f  validated filters
     */
    public static function apply(Builder $query, array $f, User $user): Builder
    {
        self::search($query, $f['q'] ?? null);

        match ($f['quick'] ?? 'all') {
            'mine' => $query->where('assigned_to', $user->id),
            'due' => $query->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', Dates::fromInput(now(Dates::zone())->toDateString())->addDay()),
            'unassigned' => $query->whereNull('assigned_to'),
            'urgent' => $query->whereIn('priority', [LeadPriority::Urgent->value, LeadPriority::High->value]),
            default => null,
        };

        $query
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('lead_status_id', $v))
            ->when($f['assigned'] ?? null, fn ($q, $v) => $v === 'unassigned' ? $q->whereNull('assigned_to') : $q->where('assigned_to', (int) $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('lead_source_id', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($f['tag'] ?? null, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->whereKey($v)))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v));

        foreach (['city', 'state', 'country'] as $column) {
            if (! empty($f[$column])) {
                $query->search($f[$column], [$column]);
            }
        }

        foreach (['created' => 'created_at', 'follow_up' => 'next_follow_up_at', 'activity' => 'last_activity_at'] as $key => $column) {
            if (! empty($f["{$key}_from"])) {
                $query->where($column, '>=', Dates::fromInput($f["{$key}_from"]));
            }
            if (! empty($f["{$key}_to"])) {
                $query->where($column, '<', Dates::fromInput($f["{$key}_to"])->addDay());
            }
        }

        return self::sort($query, $f['sort'] ?? 'created', $f['dir'] ?? 'desc');
    }

    /**
     * Name, company, email and reference (case-insensitive), phone as digits only. Every word typed
     * must match one of them, so "Victoria Sterling" finds first + last name.
     */
    public static function search(Builder $query, ?string $term): Builder
    {
        $words = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $driver = $query->getConnection()->getDriverName();

        foreach (array_slice($words, 0, 6) as $word) {
            $pattern = '%'.Searchable::escapeLike($word, $driver).'%';
            $digits = preg_replace('/\D+/', '', $word);

            $query->where(function (Builder $q) use ($pattern, $digits) {
                foreach (['first_name', 'last_name', 'company', 'email', 'secondary_email', 'reference'] as $column) {
                    $q->orWhereLike($column, $pattern);
                }
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone_normalized', 'like', "%{$digits}%");
                }
            });
        }

        return $query;
    }

    public static function sort(Builder $query, string $sort, string $dir): Builder
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'name' => $query->orderBy('first_name', $dir)->orderBy('last_name', $dir)->orderBy('company', $dir),
            'status' => $query->orderBy(LeadStatus::query()->select('sort_order')->whereColumn('lead_statuses.id', 'leads.lead_status_id'), $dir),
            // Urgent > High > Medium > Low > none (static expression, no user input).
            'priority' => $query->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 when 'low' then 1 else 0 end {$dir}"),
            'next_follow_up' => $query->orderByRaw("next_follow_up_at is null")->orderBy('next_follow_up_at', $dir),
            'deal_value' => $query->orderByRaw('deal_value is null')->orderBy('deal_value', $dir),
            'last_activity' => $query->orderByRaw('last_activity_at is null')->orderBy('last_activity_at', $dir),
            default => $query->orderBy('created_at', $dir),
        };

        return $query->orderBy('id', $dir);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public static function active(array $f): bool
    {
        return collect($f)->except(['sort', 'dir', 'quick'])->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty()
            || ($f['quick'] ?? 'all') !== 'all';
    }
}
