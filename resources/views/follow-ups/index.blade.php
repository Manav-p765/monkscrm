@php
    $tabs = collect(\App\Http\Controllers\FollowUpController::TABS)->mapWithKeys(fn ($label, $key) => [$key => [
        'label' => $label, 'count' => $counts[$key],
        'href' => route('follow-ups.index', array_filter(['tab' => $key, 'assigned' => $filters['assigned'] ?? null])),
        'active' => $tab === $key,
    ]])->all();
@endphp

<x-layouts.app title="Follow-Ups Queue" :count="$followUps->total().' items'"
                :subtitle="$canSeeAll ? 'Every scheduled touchpoint across the team, so no lead slips through the cracks' : 'Your scheduled touchpoints, so no lead slips through the cracks'">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-tabs variant="pills" label="Follow-up queues" :tabs="$tabs" />
        @if ($canSeeAll)
            <form method="GET" action="{{ route('follow-ups.index') }}" x-data="autoSubmit" x-on:change="submit" class="flex items-center gap-2 sm:w-64">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="flex-1"><x-select name="assigned" label="Assigned to" size="filter" :options="$users" :value="$filters['assigned'] ?? null" placeholder="Everyone" /></div>
                <noscript><x-button variant="secondary" :loading="false">Apply</x-button></noscript>
            </form>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs">
        @if ($followUps->isEmpty())
            <x-empty-state :title="$tab === 'completed' ? 'No completed follow-ups yet' : 'No follow-ups in this queue'" :tone="$tab === 'completed' ? 'neutral' : 'success'" icon="clock">
                {{ $tab === 'completed' ? 'Follow-ups you mark as done will appear here.' : 'You are all caught up on scheduled interactions! Schedule new follow-ups from a lead.' }}
                <x-slot:actions><x-button variant="secondary" :href="route('leads.index')">Go to Leads</x-button></x-slot:actions>
            </x-empty-state>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($followUps as $fu)
                    @include('follow-ups._item', ['fu' => $fu, 'showLead' => true])
                @endforeach
            </div>
            @if ($followUps->hasPages())
                <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50/60 p-3 px-4 text-2xs text-slate-500">
                    <span>Showing {{ $followUps->firstItem() }}–{{ $followUps->lastItem() }} of {{ $followUps->total() }}</span>
                    {{ $followUps->links('partials.pagination') }}
                </div>
            @endif
        @endif
    </div>
</x-layouts.app>
