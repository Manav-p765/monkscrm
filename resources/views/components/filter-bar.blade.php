{{--
    Prototype filter panel (LeadListView search + selects + "Reset"). A GET form: changing a filter
    submits it (Alpine `autoSubmit`), "Apply" works without JavaScript, "Clear filters" resets.

    <x-filter-bar :action="route('audit-log.index')" :active="$filtersActive">
        <x-select name="user" label="User" size="filter" :options="…" placeholder="All users" />
    </x-filter-bar>
--}}
@props(['action', 'active' => false, 'clearLabel' => 'Clear filters'])

<form method="GET" action="{{ $action }}" x-data="autoSubmit" x-on:change="submit"
      {{ $attributes->class('rounded-xl border border-slate-200 bg-white p-3 shadow-2xs') }}>
    <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center [&>*]:min-w-0 sm:[&>*]:flex-1">
        {{ $slot }}
        <div class="flex shrink-0 items-center gap-2 sm:flex-none">
            <x-button variant="secondary" :loading="false">Apply</x-button>
            @if ($active)
                <a href="{{ $action }}" class="px-1 py-1 text-xs text-slate-500 underline hover:text-slate-800">{{ $clearLabel }}</a>
            @endif
        </div>
    </div>
</form>
