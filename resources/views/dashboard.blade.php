<x-layouts.app title="Dashboard" width="6xl">
    <x-slot:header>
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-slate-900">Good day, {{ \Illuminate\Support\Str::before($user->name.' ', ' ') }}</h1>
                <p class="mt-0.5 text-xs text-slate-500">
                    @if ($user->isAdmin())
                        Organization overview &amp; company-wide lead pipeline
                    @elseif ($user->isManager())
                        Operational oversight for {{ $user->team?->name ?? 'your team' }}
                    @else
                        Your assigned leads and scheduled follow-ups
                    @endif
                </p>
            </div>
            <x-button variant="secondary" :href="route('leads.index')">View All Leads</x-button>
        </div>
    </x-slot:header>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Total Leads" :href="route('leads.index')" hint="Open lead list">{{ number_format($cards['total']) }}</x-stat>
        <x-stat label="New Leads" tone="blue" :href="route('leads.index', ['status' => \App\Support\Settings::get('default_status_id')])" hint="Awaiting first outreach">{{ number_format($cards['new']) }}</x-stat>
        <x-stat label="Contacted" tone="indigo" hint="Reached at least once">{{ number_format($cards['contacted']) }}</x-stat>
        <x-stat label="Qualified" tone="indigo" hint="Budget, authority, need verified">{{ number_format($cards['qualified']) }}</x-stat>
        <x-stat label="Follow-Ups Due Today" tone="amber" :href="route('follow-ups.index', ['tab' => 'today'])" hint="Due before midnight">{{ number_format($cards['due_today']) }}</x-stat>
        <x-stat label="Overdue Follow-Ups" tone="rose" :href="route('follow-ups.index', ['tab' => 'overdue'])" hint="Past their due time">{{ number_format($cards['overdue']) }}</x-stat>
        <x-stat label="Won" tone="emerald" hint="Closed deals">
            {{ number_format($cards['won']) }}
            <x-money :totals="$wonValue" empty="" class="block text-xs font-semibold whitespace-normal text-emerald-700" />
        </x-stat>
        <x-stat label="Lost" hint="Closed lost, not interested or invalid">{{ number_format($cards['lost']) }}</x-stat>
    </div>

    {{-- Pipeline strip --}}
    <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-bold text-slate-900">Lead Pipeline Stages</span>
            <span class="text-2xs text-slate-500">Active value: <x-money :totals="$activeTotals" class="font-bold text-slate-800" /></span>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
            @foreach ($pipeline as $row)
                <a href="{{ route('leads.index', ['status' => $row['status']->id]) }}" class="rounded-lg border border-slate-200 p-3 text-left transition hover:border-indigo-400 hover:bg-slate-50/60">
                    <div class="mb-1 flex items-center gap-1.5 text-xs text-slate-600">
                        <x-badge :color="$row['status']->color" class="border-0 bg-transparent px-0 text-xs font-semibold text-slate-600">{{ $row['status']->name }}</x-badge>
                    </div>
                    <div class="text-sm font-bold text-slate-900">{{ number_format($row['count']) }}</div>
                    <x-money :totals="$row['totals']" class="mt-0.5 block text-3xs whitespace-normal text-slate-500" />
                </a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Follow-up queue --}}
        <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Follow-Up Queue</h2>
                    <p class="text-2xs text-slate-500">Scheduled calls, meetings, &amp; check-ins</p>
                </div>
                <a href="{{ route('follow-ups.index') }}" class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800">View All <x-lucide-chevron-right class="size-3.25" aria-hidden="true" /></a>
            </div>
            <div class="mt-3 flex-1 divide-y divide-slate-100">
                @forelse ($upcoming as $fu)
                    @php
                        $late = $fu->due_at->isPast();
                        $isToday = ! $late && $fu->due_at->copy()->setTimezone(\App\Support\Dates::zone())->isToday();
                    @endphp
                    <div class="flex items-start justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('leads.show', $fu->lead) }}?tab=followups" class="truncate text-xs font-bold text-slate-900 transition hover:text-indigo-600">{{ $fu->lead->displayName() }}</a>
                                @if ($fu->lead->company)<span class="truncate text-3xs text-slate-500">· {{ $fu->lead->company }}</span>@endif
                            </div>
                            <p class="mt-1 line-clamp-1 text-xs text-slate-600">{{ $fu->notes ?: 'Scheduled '.strtolower($fu->type->label()) }}</p>
                            <div class="mt-1.5 flex items-center gap-2 text-3xs text-slate-500">
                                <span @class(['font-semibold', 'text-rose-600' => $late, 'text-amber-700' => $isToday])>
                                    @if ($late) Overdue @elseif ($isToday) Due Today @else <x-date :value="$fu->due_at" day /> @endif
                                </span>
                                <span>·</span><span>{{ $fu->type->label() }}</span><span>·</span><span>{{ $fu->assignee?->name }}</span>
                            </div>
                        </div>
                        @can('manageFollowUp', [App\Models\Lead::class, $fu])
                            <form method="POST" action="{{ route('follow-ups.update', $fu) }}" class="shrink-0">
                                @csrf @method('PATCH')<input type="hidden" name="action" value="complete">
                                <x-button variant="success" size="sm" icon="check" loading="Saving…">Done</x-button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <x-empty-state title="No pending follow-ups right now" tone="success" class="py-10">Schedule one from any lead.</x-empty-state>
                @endforelse
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="border-b border-slate-100 pb-3">
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Recent Team Activity</h2>
                <p class="text-2xs text-slate-500">Live feed of notes, calls, &amp; status updates</p>
            </div>
            <div class="mt-3 flex-1 divide-y divide-slate-100">
                @forelse ($recent as $activity)
                    <div class="flex items-start gap-2.5 py-2.5">
                        <div class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-3xs font-bold text-slate-600" aria-hidden="true">
                            {{ $activity->user?->initials() ?? 'S' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs text-slate-800"><span class="font-semibold text-slate-900">{{ $activity->user?->name ?? 'System' }}</span> <span class="text-slate-600">{{ $activity->description }}</span></div>
                            <div class="mt-0.5 flex items-center gap-1.5 text-3xs text-slate-500">
                                <a href="{{ route('leads.show', $activity->lead) }}" class="truncate font-medium text-indigo-600 hover:underline">{{ $activity->lead->displayName() }}@if ($activity->lead->company) ({{ $activity->lead->company }})@endif</a>
                                <span>·</span><x-date :value="$activity->occurred_at" relative />
                            </div>
                        </div>
                    </div>
                @empty
                    <x-empty-state title="No recent activity logged yet" icon="activity" class="py-10" />
                @endforelse
            </div>
        </div>
    </div>

    {{-- Lead sources --}}
    <div class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div>
            <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Lead Sources</h2>
            <p class="text-2xs text-slate-500">Where your leads come from</p>
        </div>
        @if ($sources->isEmpty())
            <p class="text-xs text-slate-500">No leads yet.</p>
        @else
            <x-bar-chart :rows="$sources->all()" />
        @endif
    </div>
</x-layouts.app>
