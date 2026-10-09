<x-layouts.app title="Executive & Team Reports" subtitle="Conversion metrics, team productivity, and pipeline visibility">
    <x-slot:actions>
        <x-button variant="secondary" icon="download" :href="route('reports.export', $filters)">Export CSV</x-button>
    </x-slot:actions>

    <x-filter-bar :action="route('reports.index')" :active="false">
        <x-input name="from" type="date" label="From date" size="filter" :value="$filters['from']" />
        <x-input name="to" type="date" label="To date" size="filter" :value="$filters['to']" />
    </x-filter-bar>
    <p class="-mt-3 text-2xs text-slate-500">Leads created between <strong>{{ \App\Support\Dates::day(\App\Support\Dates::fromInput($filters['from'])) }}</strong> and <strong>{{ \App\Support\Dates::day(\App\Support\Dates::fromInput($filters['to'])) }}</strong> (India time).</p>

    {{-- The prototype's four cards (won, active pipeline, win rate, average deal size), then ours. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-stat label="Closed Won Value" tone="emerald" :hint="'Across '.$cards['won'].' won '.\Illuminate\Support\Str::plural('lead', $cards['won'])">
            <x-money :totals="$wonValue" empty="—" class="block text-lg whitespace-normal" />
        </x-stat>
        <x-stat label="Active Pipeline" tone="indigo" hint="Deal value of leads in open statuses">
            <x-money :totals="$activeValue" empty="—" class="block text-lg whitespace-normal" />
        </x-stat>
        @php $closed = $cards['won'] + $cards['lost']; @endphp
        <x-stat label="Win Rate" hint="Won vs. closed (won + lost)">{{ $closed ? round(100 * $cards['won'] / $closed) : 0 }}%</x-stat>
        <x-stat label="Average Deal Size" hint="Average deal value of won leads">
            <x-money :totals="$averageDealSize" empty="—" class="block text-lg whitespace-normal" />
        </x-stat>
        <x-stat label="Leads Created" hint="In this date range">{{ number_format($cards['total']) }}</x-stat>
        <x-stat label="Follow-Up Completion" hint="Of follow-ups that were due">{{ $completion === null ? '—' : $completion.'%' }}</x-stat>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div>
                <h2 class="text-sm font-bold text-slate-900">Conversion Funnel</h2>
                <p class="mt-0.5 text-xs text-slate-500">How many leads reached each milestone (first time each happened)</p>
            </div>
            @php $created = max(1, $funnel[0]['count']); @endphp
            <x-bar-chart :rows="collect($funnel)->map(fn ($r) => [...$r, 'pct' => (int) round(100 * $r['count'] / $created)])->all()" />
        </div>

        <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div>
                <h2 class="text-sm font-bold text-slate-900">Pipeline Velocity &amp; Funnel Health</h2>
                <p class="mt-0.5 text-xs text-slate-500">Volume and value by status (values never mixed across currencies)</p>
            </div>
            <x-bar-chart :rows="$pipeline->map(fn ($r) => ['label' => $r['status']->name, 'count' => $r['count'], 'aside' => \App\Support\Money::formatTotals($r['totals'])])->all()" />
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @foreach (['Leads by Source' => $bySource, 'Leads by Assignee' => $byAssignee, 'Leads by Team' => $byTeam] as $title => $rows)
            <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
                <h2 class="text-sm font-bold text-slate-900">{{ $title }}</h2>
                @if ($rows->isEmpty())
                    <p class="text-xs text-slate-500">No leads in this range.</p>
                @else
                    <x-bar-chart :rows="$rows->take(10)->all()" />
                @endif
            </div>
        @endforeach
    </div>

    <x-table title="Sales Representative Scorecard" :aside="$scorecard->count().' people with leads'">
        <x-slot:head>
            <tr>
                <th>Assigned To</th>
                <th>Team</th>
                <th class="text-center">Assigned</th>
                <th class="text-center">Contacted</th>
                <th class="text-center">Qualified</th>
                <th class="text-center">Won</th>
                <th class="text-right">Won Value</th>
                <th class="text-right">Avg. Time to First Contact</th>
                <th class="text-center">Follow-Up Completion</th>
            </tr>
        </x-slot:head>
        @forelse ($scorecard as $row)
            <tr>
                <td>
                    <div class="flex items-center gap-2.5 font-bold text-slate-900">
                        <x-avatar :user="$row['user']" size="md" class="size-7" />
                        <div><div>{{ $row['user']->name }}</div><div class="text-3xs font-normal text-slate-500">{{ $row['user']->email }}</div></div>
                    </div>
                </td>
                <td class="text-slate-600">{{ $row['user']->team?->name ?? '—' }}</td>
                <td class="text-center font-bold text-slate-800">{{ $row['assigned'] }}</td>
                <td class="text-center text-slate-700">{{ $row['contacted'] }}</td>
                <td class="text-center text-slate-700">{{ $row['qualified'] }}</td>
                <td class="text-center font-bold text-emerald-700">{{ $row['won'] }}</td>
                <td class="text-right font-bold text-emerald-800"><x-money :totals="$row['won_value']" class="whitespace-normal" /></td>
                <td class="text-right text-slate-700">{{ $row['avg_hours_to_contact'] === null ? '—' : ($row['avg_hours_to_contact'] < 48 ? $row['avg_hours_to_contact'].' h' : round($row['avg_hours_to_contact'] / 24, 1).' days') }}</td>
                <td class="text-center">
                    @if ($row['follow_up_rate'] === null)
                        <span class="text-slate-500">—</span>
                    @else
                        <span @class(['rounded px-2 py-0.5 text-xs font-bold', 'bg-emerald-50 text-emerald-700' => $row['follow_up_rate'] >= 80, 'bg-amber-50 text-amber-700' => $row['follow_up_rate'] < 80])>{{ $row['follow_up_rate'] }}%</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9"><x-empty-state title="No assigned leads in this range" icon="bar-chart-3">Try a wider date range.</x-empty-state></td></tr>
        @endforelse
    </x-table>
</x-layouts.app>
