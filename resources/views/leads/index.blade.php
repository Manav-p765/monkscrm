@php
    $user = auth()->user();
    $query = request()->query();
    $sortLink = function (string $key) use ($filters) {
        $dir = ($filters['sort'] ?? 'created') === $key && ($filters['dir'] ?? 'desc') === 'desc' ? 'asc' : 'desc';

        return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null]);
    };
    $sortIcon = fn (string $key) => ($filters['sort'] ?? 'created') === $key ? (($filters['dir'] ?? 'desc') === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-up-down';
    $today = now(\App\Support\Dates::zone())->toDateString();
@endphp

<x-layouts.app :title="$archived ? 'Archived Leads' : 'Lead Directory'" :count="$leads->total().' leads'"
                :subtitle="$archived ? 'Leads hidden from lists. Restore one to bring it back.' : 'Search, filter, assign, and track progress across all leads'">
    <x-slot:actions>
        <x-tabs variant="segmented" label="View" :tabs="[
            'table' => ['label' => 'Table', 'icon' => 'layout-list', 'href' => route('leads.index', collect($query)->except(['page'])->all()), 'active' => true],
            'pipeline' => ['label' => 'Pipeline', 'icon' => 'kanban', 'href' => route('pipeline.index', collect($query)->except(['page', 'sort', 'dir', 'archived'])->all()), 'active' => false],
        ]" />
        @can('export', App\Models\Lead::class)
            @unless ($archived)
                <x-button type="button" variant="secondary" icon="download" x-data="modalTrigger" data-modal="export-leads" x-on:click="open">Export CSV</x-button>
            @endunless
        @endcan
        @can('create', App\Models\Lead::class)
            <x-button :href="route('leads.create', $query)" icon="plus" class="active:scale-95">Add Lead</x-button>
        @endcan
    </x-slot:actions>
    @can('viewArchived', App\Models\Lead::class)
        <x-slot:more>
            @if ($archived)
                <a href="{{ route('leads.index') }}"><x-lucide-layout-list class="size-3.5 text-slate-400" aria-hidden="true" /> Back to active leads</a>
            @else
                <a href="{{ route('leads.index', ['archived' => 1]) }}"><x-lucide-archive class="size-3.5 text-slate-400" aria-hidden="true" /> View archived leads</a>
            @endif
        </x-slot:more>
    @endcan

    @include('leads._filters', ['route' => 'leads.index'])

    @can('export', App\Models\Lead::class)
        <x-modal name="export-leads" title="Export Leads to CSV" :subtitle="number_format($leads->total()).' leads match your current filters (up to '.number_format(\App\Http\Controllers\LeadExportController::MAX_ROWS).' are exported)'" size="lg">
            <form id="export-form" method="GET" action="{{ route('leads.export') }}" class="space-y-3">
                @foreach (collect($filters)->filter(fn ($v) => $v !== null && $v !== '') as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <fieldset>
                    <legend class="mb-2 text-xs font-semibold text-slate-700">Columns to include</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach (\App\Http\Controllers\LeadExportController::COLUMNS as $key => $label)
                            <x-checkbox name="columns[]" :value="$key" :label="$label" :id="'export-col-'.$key"
                                        :checked="in_array($key, \App\Http\Controllers\LeadExportController::DEFAULT_COLUMNS, true)" />
                        @endforeach
                    </div>
                </fieldset>
                <p class="text-2xs text-slate-500">Exports are recorded in the audit log. You can export up to 10 times an hour.</p>
            </form>
            <x-slot:footer>
                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                <x-button form="export-form" icon="download" :loading="false" x-on:click="close">Download CSV</x-button>
            </x-slot:footer>
        </x-modal>
    @endcan

    <div x-data="bulkSelect" class="space-y-4">
        <form id="bulk-form" method="POST" action="{{ route('leads.bulk') }}" class="hidden">@csrf</form>

        {{-- Bulk action bar (prototype: dark bar when leads are selected) --}}
        @unless ($archived)
            <div x-cloak x-show="any" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-900 p-3 px-4 text-white shadow-lg">
                <div class="flex items-center gap-2 text-xs">
                    <span class="rounded bg-indigo-500 px-2 py-0.5 font-bold text-white" x-text="count">0</span>
                    <span>leads selected</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-dropdown up width="w-52">
                        <x-slot:trigger><x-button type="button" variant="dark" size="sm">Change Status <x-lucide-chevron-down class="size-3" aria-hidden="true" /></x-button></x-slot:trigger>
                        @foreach ($lookups['statuses']->where('is_active', true) as $status)
                            <button type="submit" form="bulk-form" name="op" value="status:{{ $status->id }}" class="text-slate-900">
                                <x-badge :color="$status->color">{{ $status->name }}</x-badge>
                            </button>
                        @endforeach
                    </x-dropdown>
                    <x-dropdown up width="w-52">
                        <x-slot:trigger><x-button type="button" variant="dark" size="sm" icon="user-check">Assign To <x-lucide-chevron-down class="size-3" aria-hidden="true" /></x-button></x-slot:trigger>
                        @if ($lookups['canAssignOthers'])
                            <button type="submit" form="bulk-form" name="op" value="assign:none" class="text-rose-600">Unassign leads</button>
                        @endif
                        @foreach ($lookups['assignees'] as $id => $name)
                            <button type="submit" form="bulk-form" name="op" value="assign:{{ $id }}" class="text-slate-900">{{ $name }}</button>
                        @endforeach
                    </x-dropdown>
                    <x-dropdown up width="w-52">
                        <x-slot:trigger><x-button type="button" variant="dark" size="sm" icon="tag">Add Tag <x-lucide-chevron-down class="size-3" aria-hidden="true" /></x-button></x-slot:trigger>
                        @foreach ($lookups['tags'] as $tag)
                            <button type="submit" form="bulk-form" name="op" value="tag_add:{{ $tag->id }}" class="text-slate-900">{{ $tag->name }}</button>
                        @endforeach
                    </x-dropdown>
                    <x-dropdown up width="w-52">
                        <x-slot:trigger><x-button type="button" variant="dark" size="sm" icon="tag">Remove Tag <x-lucide-chevron-down class="size-3" aria-hidden="true" /></x-button></x-slot:trigger>
                        @foreach ($lookups['tags'] as $tag)
                            <button type="submit" form="bulk-form" name="op" value="tag_remove:{{ $tag->id }}" class="text-slate-900">{{ $tag->name }}</button>
                        @endforeach
                    </x-dropdown>
                    @can('viewArchived', App\Models\Lead::class)
                        <button type="button" x-data="modalTrigger" data-modal="bulk-archive" x-on:click="open"
                                class="flex items-center gap-1.5 rounded-lg bg-rose-600/90 px-2.5 py-1.5 text-xs font-medium transition hover:bg-rose-600">
                            <x-lucide-archive class="size-3.25" aria-hidden="true" /> Archive
                        </button>
                    @endcan
                    <button type="button" x-on:click="clear" class="px-2 py-1 text-xs text-slate-300 hover:text-white">Cancel</button>
                </div>
            </div>
        @endunless

        @can('viewArchived', App\Models\Lead::class)
            <x-modal name="bulk-archive" title="Archive the selected leads?" size="sm">
                <p class="text-xs leading-relaxed text-slate-600">
                    <strong class="text-slate-900"><span x-text="count">0</span> selected leads</strong> will be hidden from lists and the pipeline. You can restore them from Archived leads.
                </p>
                <x-slot:footer>
                    <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                    <x-button form="bulk-form" name="op" value="archive" variant="danger" loading="Archiving…">Archive Leads</x-button>
                </x-slot:footer>
            </x-modal>
        @endcan

        <x-table :paginator="$leads">
            <x-slot:head>
                <tr>
                    <th class="w-10">
                        @unless ($archived)
                            <input type="checkbox" x-on:change="toggleAll" x-bind:checked="allChecked" aria-label="Select all leads on this page"
                                   class="size-4 rounded border-slate-300 accent-indigo-600">
                        @endunless
                    </th>
                    <th><a href="{{ $sortLink('name') }}" class="flex items-center gap-1.5 hover:text-slate-900">Lead &amp; Organization <x-dynamic-component :component="'lucide-'.$sortIcon('name')" class="size-3 text-slate-400" aria-hidden="true" /></a></th>
                    <th><a href="{{ $sortLink('status') }}" class="flex items-center gap-1.5 hover:text-slate-900">Status <x-dynamic-component :component="'lucide-'.$sortIcon('status')" class="size-3 text-slate-400" aria-hidden="true" /></a></th>
                    <th><a href="{{ $sortLink('priority') }}" class="flex items-center gap-1.5 hover:text-slate-900">Priority <x-dynamic-component :component="'lucide-'.$sortIcon('priority')" class="size-3 text-slate-400" aria-hidden="true" /></a></th>
                    <th>Contact</th>
                    <th>Assigned To</th>
                    <th><a href="{{ $sortLink('next_follow_up') }}" class="flex items-center gap-1.5 hover:text-slate-900">Next Follow-Up <x-dynamic-component :component="'lucide-'.$sortIcon('next_follow_up')" class="size-3 text-slate-400" aria-hidden="true" /></a></th>
                    <th class="text-right"><a href="{{ $sortLink('deal_value') }}" class="flex items-center justify-end gap-1.5 hover:text-slate-900">Deal Value <x-dynamic-component :component="'lucide-'.$sortIcon('deal_value')" class="size-3 text-slate-400" aria-hidden="true" /></a></th>
                    <th>Source</th>
                </tr>
            </x-slot:head>

            @forelse ($leads as $lead)
                @php
                    $due = $lead->next_follow_up_at;
                    $overdue = $due && $due->isPast();
                    $dueToday = $due && ! $overdue && $due->copy()->setTimezone(\App\Support\Dates::zone())->toDateString() === $today;
                    $href = $archived ? null : route('leads.show', [$lead, ...$query]);
                @endphp
                <tr @if ($href) data-href="{{ $href }}" @endif @class(['bg-indigo-50/40' => $panel?->is($lead)])>
                    <td>
                        @unless ($archived)
                            <input type="checkbox" name="ids[]" value="{{ $lead->id }}" form="bulk-form" x-on:change="update"
                                   aria-label="Select {{ $lead->displayName() }}" class="size-4 rounded border-slate-300 accent-indigo-600">
                        @endunless
                    </td>
                    <td class="min-w-50">
                        <div class="flex items-center gap-2">
                            @if ($href)
                                <a href="{{ $href }}" class="font-bold text-slate-900 transition hover:text-indigo-600">{{ $lead->displayName() }}</a>
                            @else
                                <span class="font-bold text-slate-900">{{ $lead->displayName() }}</span>
                            @endif
                            <span class="font-mono text-3xs text-slate-500">{{ $lead->reference }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-2xs text-slate-500">
                            <x-lucide-building class="size-2.75 text-slate-400" aria-hidden="true" />
                            <span class="truncate">{{ $lead->company ?: 'Individual' }}</span>
                            @if ($lead->job_title)<span>· {{ $lead->job_title }}</span>@endif
                        </div>
                    </td>
                    <td class="whitespace-nowrap"><x-badge :color="$lead->status->color">{{ $lead->status->name }}</x-badge></td>
                    <td class="whitespace-nowrap"><x-priority :value="$lead->priority" /></td>
                    <td class="whitespace-nowrap">
                        <div class="space-y-0.5">
                            @if ($lead->email)
                                <a href="mailto:{{ $lead->email }}" class="flex items-center gap-1 truncate text-2xs text-slate-600 hover:text-indigo-600">
                                    <x-lucide-mail class="size-2.75 text-slate-400" aria-hidden="true" /><span>{{ $lead->email }}</span>
                                </a>
                            @endif
                            @if ($lead->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $lead->phone) }}" class="flex items-center gap-1 truncate text-2xs text-slate-500 hover:text-indigo-600">
                                    <x-lucide-phone class="size-2.75 text-slate-400" aria-hidden="true" /><span>{{ $lead->phone }}</span>
                                </a>
                            @endif
                        </div>
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($lead->assignee)
                            <span class="flex items-center gap-2"><x-avatar :user="$lead->assignee" size="xs" class="size-5" /><span class="text-2xs font-medium text-slate-700">{{ $lead->assignee->name }}</span></span>
                        @else
                            <span class="text-2xs text-slate-500 italic">Unassigned</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($due)
                            <div @class(['flex items-center gap-1 text-2xs font-medium', 'font-bold text-rose-600' => $overdue, 'font-bold text-amber-700' => $dueToday, 'text-slate-700' => ! $overdue && ! $dueToday])>
                                <x-lucide-clock class="size-2.75" aria-hidden="true" /><x-date :value="$due" day />
                            </div>
                            @if ($overdue)<span class="text-3xs font-semibold text-rose-600">Overdue</span>@endif
                            @if ($dueToday)<span class="text-3xs font-semibold text-amber-700">Due Today</span>@endif
                        @else
                            <span class="text-2xs text-slate-500">—</span>
                        @endif
                    </td>
                    <td class="text-right font-semibold whitespace-nowrap text-slate-800"><x-money :amount="$lead->deal_value" :currency="$lead->currency" /></td>
                    <td class="text-2xs whitespace-nowrap text-slate-500">
                        {{ $lead->source?->name ?? '—' }}
                        @if ($archived)
                            @can('restore', $lead)
                                <form method="POST" action="{{ route('leads.restore', $lead) }}" class="mt-1">@csrf<x-button variant="text" size="sm" loading="Restoring…">Restore</x-button></form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        @if ($archived)
                            <x-empty-state title="No archived leads" icon="archive">Archived leads will appear here.</x-empty-state>
                        @elseif ($filtersActive)
                            <x-empty-state title="No leads match your criteria">
                                Try clearing some filters or searching with a different term.
                                <x-slot:actions><x-button variant="text" :href="route('leads.index')">Reset all filters</x-button></x-slot:actions>
                            </x-empty-state>
                        @else
                            <x-empty-state title="No leads yet" icon="users-round">
                                Add your first lead or import a CSV.
                                @can('create', App\Models\Lead::class)
                                    <x-slot:actions><x-button :href="route('leads.create')" icon="plus">Add Lead</x-button></x-slot:actions>
                                @endcan
                            </x-empty-state>
                        @endif
                    </td>
                </tr>
            @endforelse

            <x-slot:footer>
                <span>Showing {{ $leads->firstItem() ?? 0 }}–{{ $leads->lastItem() ?? 0 }} of {{ $leads->total() }} leads</span>
            </x-slot:footer>
        </x-table>
    </div>

    @if ($panel)
        @include('leads._panel', ['lead' => $panel])
    @endif
    @if ($form)
        @include('leads._form', ['lead' => $form])
    @endif
</x-layouts.app>
