{{-- Prototype quick-filter pills + filter panel, shared by the list and the pipeline. --}}
@php
    $base = collect($filters)->except(['quick'])->filter(fn ($v) => $v !== null && $v !== '')->all();
    $quickTabs = collect(\App\Support\LeadFilters::QUICK)->mapWithKeys(fn ($label, $key) => [$key => [
        'label' => $label,
        'href' => route($route, [...$base, ...($key === 'all' ? [] : ['quick' => $key]), ...(! empty($archived) ? ['archived' => 1] : [])]),
        'active' => ($filters['quick'] ?? 'all') === $key,
    ]])->all();
    $assignedOptions = ['unassigned' => 'Unassigned'] + $lookups['users']->pluck('name', 'id')->all();
@endphp

<x-tabs variant="pills" label="Quick filters" :tabs="$quickTabs" />

<form method="GET" action="{{ route($route) }}" x-data="autoSubmit" x-on:change="submit"
      class="space-y-3 rounded-xl border border-slate-200 bg-white p-3 shadow-2xs">
    @if (! empty($filters['quick']) && $filters['quick'] !== 'all')
        <input type="hidden" name="quick" value="{{ $filters['quick'] }}">
    @endif
    @if (! empty($archived))
        <input type="hidden" name="archived" value="1">
    @endif
    @foreach (['sort', 'dir'] as $keep)
        @if (! empty($filters[$keep]))
            <input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">
        @endif
    @endforeach

    <div x-data="moreDetails" data-open="{{ $advancedOpen ? 'true' : 'false' }}">
        <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <x-input name="q" label="Search leads" size="filter" icon="search" :value="$filters['q'] ?? null"
                         placeholder="Search leads by name, company, email, phone…" />
            </div>
            @if ($showStatus ?? true)
                <div class="sm:w-44"><x-select name="status" label="Status" size="filter" :options="$lookups['statuses']->pluck('name', 'id')" :value="$filters['status'] ?? null" placeholder="All Stages" /></div>
            @endif
            <div class="sm:w-44"><x-select name="assigned" label="Assigned to" size="filter" :options="$assignedOptions" :value="$filters['assigned'] ?? null" placeholder="Anyone" /></div>
            <button type="button" x-on:click="toggle" x-bind:aria-expanded="open"
                    @class(['flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-medium transition',
                        'border-indigo-200 bg-indigo-50 font-semibold text-indigo-700' => $advancedOpen,
                        'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' => ! $advancedOpen])>
                <x-lucide-sliders-horizontal class="size-3.25" aria-hidden="true" />
                <span>More</span>
            </button>
            <x-button variant="secondary" :loading="false" class="sm:hidden">Apply</x-button>
            @if ($filtersActive)
                <a href="{{ route($route, ! empty($archived) ? ['archived' => 1] : []) }}" class="px-1 py-1 text-xs text-slate-500 underline hover:text-slate-800">Clear filters</a>
            @endif
        </div>

        <div x-cloak x-show="open" class="mt-3 grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3 lg:grid-cols-4 [&_label]:text-3xs [&_label]:font-bold [&_label]:tracking-wide [&_label]:text-slate-500 [&_label]:uppercase">
            <x-select name="source" label="Lead Source" :options="$lookups['sources']->pluck('name', 'id')" :value="$filters['source'] ?? null" placeholder="All Sources" />
            <x-select name="priority" label="Priority" :options="collect($lookups['priorities'])->mapWithKeys(fn ($p) => [$p->value => $p->label()])" :value="$filters['priority'] ?? null" placeholder="All Priorities" />
            <x-select name="tag" label="Tag" :options="$lookups['tags']->pluck('name', 'id')" :value="$filters['tag'] ?? null" placeholder="All Tags" />
            <x-select name="currency" label="Currency" :options="collect(\App\Enums\Currency::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value.' · '.$c->label()])" :value="$filters['currency'] ?? null" placeholder="Any Currency" />
            <x-input name="city" label="City" :value="$filters['city'] ?? null" />
            <x-input name="state" label="State" :value="$filters['state'] ?? null" />
            <x-input name="country" label="Country" :value="$filters['country'] ?? null" />
            <div></div>
            <x-input type="date" name="created_from" label="Created From" :value="$filters['created_from'] ?? null" />
            <x-input type="date" name="created_to" label="Created To" :value="$filters['created_to'] ?? null" />
            <x-input type="date" name="follow_up_from" label="Follow-Up From" :value="$filters['follow_up_from'] ?? null" />
            <x-input type="date" name="follow_up_to" label="Follow-Up To" :value="$filters['follow_up_to'] ?? null" />
            <x-input type="date" name="activity_from" label="Last Activity From" :value="$filters['activity_from'] ?? null" />
            <x-input type="date" name="activity_to" label="Last Activity To" :value="$filters['activity_to'] ?? null" />
            <div class="flex items-end"><x-button variant="secondary" :loading="false">Apply Filters</x-button></div>
        </div>
    </div>
</form>
