<x-layouts.app title="Audit Log" subtitle="Every sign-in, user, settings and data change, newest first. Entries can't be edited or deleted."
                :count="$entries->total().' entries'">
    <x-filter-bar :action="route('audit-log.index')" :active="$filtersActive">
        <x-select name="user" label="User" size="filter" :options="$users" :value="$filters['user'] ?? null" placeholder="All users" />
        <x-select name="action" label="Action" size="filter" :options="$actions" :value="$filters['action'] ?? null" placeholder="All actions" />
        <x-input name="from" type="date" label="From date" size="filter" :value="$filters['from'] ?? null" />
        <x-input name="to" type="date" label="To date" size="filter" :value="$filters['to'] ?? null" />
    </x-filter-bar>

    <x-table :paginator="$entries">
        <x-slot:head>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>About</th>
                <th>IP Address</th>
            </tr>
        </x-slot:head>

        @forelse ($entries as $entry)
            <tr data-href="{{ route('audit-log.show', [$entry, ...request()->query()]) }}" @class(['bg-indigo-50/40' => $selected?->is($entry)])>
                <td class="whitespace-nowrap">
                    <a href="{{ route('audit-log.show', [$entry, ...request()->query()]) }}" class="font-semibold text-slate-900 hover:text-indigo-600">
                        <x-date :value="$entry->created_at" relative />
                    </a>
                    <div class="text-2xs text-slate-500"><x-date :value="$entry->created_at" /></div>
                </td>
                <td class="whitespace-nowrap">
                    @if ($entry->user)
                        <span class="flex items-center gap-2">
                            <x-avatar :user="$entry->user" />
                            <span class="font-medium text-slate-700">{{ $entry->user->name }}</span>
                        </span>
                    @else
                        <span class="text-slate-500 italic">No user</span>
                    @endif
                </td>
                <td>
                    <span @class(['font-medium', 'text-rose-700' => in_array($entry->action, ['auth.login_failed', 'auth.lockout'], true), 'text-slate-800' => ! in_array($entry->action, ['auth.login_failed', 'auth.lockout'], true)])>
                        {{ $entry->actionLabel() }}
                    </span>
                </td>
                <td class="text-slate-600">
                    {{ $entry->subjectLabel() ?? ($entry->meta['email'] ?? '—') }}
                </td>
                <td class="font-mono text-2xs whitespace-nowrap text-slate-500">{{ $entry->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-empty-state :title="$filtersActive ? 'No entries match these filters' : 'Nothing logged yet'" icon="history">
                        @if ($filtersActive)
                            Try a wider date range or clear the filters.
                        @else
                            Sign-ins and changes to users and settings will appear here.
                        @endif
                        @if ($filtersActive)
                            <x-slot:actions><x-button variant="text" :href="route('audit-log.index')">Clear filters</x-button></x-slot:actions>
                        @endif
                    </x-empty-state>
                </td>
            </tr>
        @endforelse
    </x-table>

    @if ($selected)
        <x-slide-over :close-href="route('audit-log.index', request()->query())" label="Audit log entry" width="max-w-xl">
            <x-slot:header>
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-xl font-bold text-slate-900">{{ $selected->actionLabel() }}</h2>
                        <p class="mt-1 text-xs text-slate-600"><x-date :value="$selected->created_at" /> · <x-date :value="$selected->created_at" relative /></p>
                    </div>
                    <a href="{{ route('audit-log.index', request()->query()) }}" class="rounded-lg border border-slate-200 p-2 text-slate-400 transition hover:bg-white hover:text-slate-700">
                        <span class="sr-only">Close</span>
                        <x-lucide-x class="size-4" aria-hidden="true" />
                    </a>
                </div>
            </x-slot:header>

            <div class="space-y-6 p-6">
                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-xs font-bold tracking-wider text-slate-800 uppercase">Details</h3>
                    <dl class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                        <div>
                            <dt class="block text-3xs font-semibold text-slate-500 uppercase">User</dt>
                            <dd class="font-medium text-slate-800">{{ $selected->user ? $selected->user->name.' ('.$selected->user->email.')' : 'No user' }}</dd>
                        </div>
                        <div>
                            <dt class="block text-3xs font-semibold text-slate-500 uppercase">Action Key</dt>
                            <dd class="font-mono text-2xs text-slate-700">{{ $selected->action }}</dd>
                        </div>
                        <div>
                            <dt class="block text-3xs font-semibold text-slate-500 uppercase">About</dt>
                            <dd class="font-medium text-slate-800">
                                {{ $selected->subjectLabel() ?? '—' }}
                                @if ($selected->subjectType())
                                    <span class="text-slate-500">({{ $selected->subjectType() }})</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="block text-3xs font-semibold text-slate-500 uppercase">IP Address</dt>
                            <dd class="font-mono text-2xs text-slate-700">{{ $selected->ip_address ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="block text-3xs font-semibold text-slate-500 uppercase">Browser</dt>
                            <dd class="break-words text-slate-700">{{ $selected->user_agent ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-xs font-bold tracking-wider text-slate-800 uppercase">What Changed</h3>
                    @if ($selected->meta)
                        <dl class="space-y-2 text-xs">
                            @foreach ($selected->meta as $key => $value)
                                <div class="grid grid-cols-3 gap-3">
                                    <dt class="font-semibold text-slate-600">{{ \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                    <dd class="col-span-2 break-words text-slate-800">
                                        @if (is_array($value))
                                            <code class="font-mono text-2xs">{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                                        @elseif (is_bool($value))
                                            {{ $value ? 'Yes' : 'No' }}
                                        @else
                                            {{ $value ?? '—' }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="text-xs text-slate-500">No extra details for this entry.</p>
                    @endif
                </div>
            </div>
        </x-slide-over>
    @endif
</x-layouts.app>
