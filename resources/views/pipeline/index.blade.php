@php
    $query = request()->query();
    $user = auth()->user();
    // Rendered once and reused on every card (cheaper than a component per card).
    $icons = [
        'grip' => svg('lucide-grip-vertical', 'mt-0.5 size-3.5 shrink-0 text-slate-300 group-hover:text-slate-500', ['aria-hidden' => 'true'])->toHtml(),
        'building' => svg('lucide-building', 'size-3 shrink-0 text-slate-400', ['aria-hidden' => 'true'])->toHtml(),
        'clock' => svg('lucide-clock', 'size-2.5', ['aria-hidden' => 'true'])->toHtml(),
    ];
    // Same mapping as <x-priority> (only High and Urgent are coloured).
    $priorityStyles = [
        'urgent' => ['text-rose-700 font-semibold', 'bg-rose-600'],
        'high' => ['text-amber-700 font-medium', 'bg-amber-500'],
        'medium' => ['text-slate-600', 'bg-slate-400'],
        'low' => ['text-slate-500', 'bg-slate-300'],
    ];
@endphp

<x-layouts.app title="Sales Pipeline" :count="$total.' leads'" subtitle="Drag & drop cards across stages to advance leads in the pipeline" width="full">
    <x-slot:actions>
        <x-tabs variant="segmented" label="View" :tabs="[
            'table' => ['label' => 'Table', 'icon' => 'layout-list', 'href' => route('leads.index', collect($query)->except(['show'])->all()), 'active' => false],
            'pipeline' => ['label' => 'Pipeline', 'icon' => 'kanban', 'href' => route('pipeline.index', $query), 'active' => true],
        ]" />
        @can('create', App\Models\Lead::class)
            <x-button :href="route('leads.create')" icon="plus" class="active:scale-95">Add Lead</x-button>
        @endcan
    </x-slot:actions>

    @include('leads._filters', ['route' => 'pipeline.index', 'showStatus' => false, 'advancedOpen' => collect($filters)->only(\App\Support\LeadFilters::ADVANCED)->filter()->isNotEmpty()])

    <div x-data="kanban" class="relative space-y-3">
        <div x-cloak x-show="showToast" x-bind:class="toastClasses" role="status"
             class="flex items-center gap-2 rounded-xl border p-2.5 px-4 text-xs font-semibold shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0" aria-hidden="true" />
            <span x-text="message"></span>
        </div>

        <div class="flex items-center justify-between px-1 text-xs text-slate-500">
            <span class="flex items-center gap-1.5">
                <x-lucide-sparkles class="size-3.25 text-indigo-600" aria-hidden="true" />
                <span>Drag cards between stages, or use “Move to…” on a card</span>
            </span>
            <span class="text-2xs font-medium">{{ $total }} leads in view · {{ $columns->count() }} stages</span>
        </div>

        <div class="overflow-x-auto pb-4">
            <div class="flex min-w-max items-start gap-4">
                @foreach ($columns as $column)
                    @php $status = $column['status']; @endphp
                    <section aria-label="{{ $status->name }}" data-status="{{ $status->id }}" data-name="{{ $status->name }}"
                             x-on:dragover="over" x-on:drop="drop"
                             class="flex min-h-130 w-76 shrink-0 flex-col rounded-2xl border border-slate-200/90 bg-slate-100/70 p-3 transition-all duration-150">
                        <header class="mb-2.5 border-b border-slate-200/90 pb-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <x-badge :color="$status->color" class="border-0 bg-transparent px-0 text-xs font-bold text-slate-900">{{ $status->name }}</x-badge>
                                    <span data-count class="rounded-full border border-slate-200 bg-white px-1.5 text-3xs font-bold text-slate-700">{{ $column['count'] }}</span>
                                </div>
                            </div>
                            <x-money :totals="$column['totals']" empty="" data-totals class="mt-1 block text-xs font-bold whitespace-normal text-slate-700" />
                        </header>

                        <div data-cards class="min-h-95 flex-1 space-y-2.5">
                            @foreach ($column['cards'] as $lead)
                                @php
                                    // Lean markup on purpose: up to 50 cards per column, so no nested components per card.
                                    $due = $lead->next_follow_up_at;
                                    $late = $due && $due->isPast();
                                    $isToday = ! $late && \App\Support\Dates::isToday($due);
                                    $canMove = $user->can('update', $lead);
                                    $pr = $lead->priority?->value;
                                @endphp
                                <article @if ($canMove) draggable="true" x-on:dragstart="start" x-on:dragend="end" @endif
                                         data-ref="{{ $lead->reference }}" data-name="{{ $lead->displayName() }}" data-status="{{ $status->id }}"
                                         data-url="{{ route('leads.status', $lead) }}"
                                         class="group rounded-xl border border-slate-200 bg-white p-3 shadow-xs transition-all duration-150 select-none hover:border-indigo-400 hover:shadow-sm {{ $canMove ? 'cursor-grab active:cursor-grabbing' : '' }}">
                                    <div class="mb-1.5 flex items-start justify-between gap-1.5">
                                        <div class="flex min-w-0 items-start gap-1.5">
                                            {!! $icons['grip'] !!}
                                            <a href="{{ route('leads.show', $lead) }}" class="block truncate text-xs font-bold text-slate-900 transition group-hover:text-indigo-600">{{ $lead->displayName() }}</a>
                                        </div>
                                        <span class="shrink-0 rounded border border-slate-100 bg-slate-50 px-1 py-0.5 font-mono text-4xs text-slate-500">{{ $lead->reference }}</span>
                                    </div>
                                    <div class="mb-2.5 flex items-center gap-1.5 truncate pl-5 text-2xs text-slate-600">
                                        {!! $icons['building'] !!}
                                        <span class="truncate font-medium">{{ $lead->company ?: 'Individual' }}</span>
                                        @if ($lead->job_title)<span class="truncate text-3xs text-slate-500">· {{ $lead->job_title }}</span>@endif
                                    </div>
                                    <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-xs">
                                        <span class="text-xs font-bold whitespace-nowrap text-slate-900 tabular-nums">{{ $lead->deal_value !== null ? \App\Support\Money::format($lead->deal_value, $lead->currency) : '—' }}</span>
                                        @if ($pr)
                                            <span class="inline-flex items-center gap-1.5 text-3xs whitespace-nowrap">
                                                <span class="size-1.5 rounded-full {{ $priorityStyles[$pr][1] }}" aria-hidden="true"></span>
                                                <span class="{{ $priorityStyles[$pr][0] }}">{{ ucfirst($pr) }}</span>
                                            </span>
                                        @endif
                                    </div>
                                    @if ($lead->tags->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap items-center gap-1 border-t border-slate-50 pt-1.5">
                                            @foreach ($lead->tags->take(2) as $tag)
                                                <span class="rounded border border-slate-200 bg-slate-100 px-1.5 text-4xs font-semibold text-slate-600">{{ $tag->name }}</span>
                                            @endforeach
                                            @if ($lead->tags->count() > 2)<span class="text-4xs text-slate-500">+{{ $lead->tags->count() - 2 }}</span>@endif
                                        </div>
                                    @endif
                                    <div class="mt-2.5 flex items-center justify-between border-t border-slate-100 pt-2 text-2xs text-slate-500">
                                        @if ($lead->assignee)
                                            <span class="flex min-w-0 items-center gap-1.5">
                                                <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full border border-indigo-200 bg-indigo-50 text-4xs font-bold text-indigo-700" aria-hidden="true">{{ $lead->assignee->initials() }}</span>
                                                <span class="max-w-21.25 truncate text-3xs text-slate-600">{{ \Illuminate\Support\Str::before($lead->assignee->name.' ', ' ') }}</span>
                                            </span>
                                        @else
                                            <span class="text-3xs text-slate-500 italic">Unassigned</span>
                                        @endif
                                        @if ($due)
                                            <span class="flex items-center gap-1 rounded px-1.5 py-0.5 text-3xs font-semibold {{ $late ? 'bg-rose-50 text-rose-700' : ($isToday ? 'bg-amber-50 text-amber-700' : 'text-slate-500') }}">
                                                {!! $icons['clock'] !!}<time datetime="{{ \App\Support\Dates::iso($due) }}">{{ \App\Support\Dates::day($due) }}</time>
                                            </span>
                                        @endif
                                    </div>
                                    @if ($canMove)
                                        {{-- Keyboard-accessible alternative to dragging --}}
                                        <form method="POST" action="{{ route('leads.status', $lead) }}" x-data="autoSubmit" x-on:change="submit"
                                              class="mt-2 flex items-center justify-end gap-1.5 border-t border-slate-100 pt-2">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="via" value="kanban">
                                            <label for="move-{{ $lead->id }}" class="text-3xs font-semibold tracking-wider text-slate-500 uppercase">Move to…</label>
                                            <select id="move-{{ $lead->id }}" name="lead_status_id" class="max-w-32 rounded border border-slate-200 bg-white px-1 py-0.5 text-3xs text-slate-700 focus:border-indigo-500 focus:outline-none">
                                                @foreach ($statuses as $target)
                                                    <option value="{{ $target->id }}" @selected($target->id === $status->id)>{{ $target->name }}</option>
                                                @endforeach
                                            </select>
                                            <noscript><button type="submit" class="text-3xs font-semibold text-indigo-600">Move</button></noscript>
                                        </form>
                                    @endif
                                </article>
                            @endforeach

                            @if ($column['count'] === 0)
                                <div class="flex h-36 flex-col items-center justify-center rounded-xl border border-dashed border-slate-300/80 p-4 text-center text-slate-500 select-none">
                                    <div class="mb-1.5 flex size-8 items-center justify-center rounded-full bg-slate-200/60 text-slate-400"><x-lucide-grip-vertical class="size-4" aria-hidden="true" /></div>
                                    <span class="text-xs font-medium">No leads in stage</span>
                                    <span class="mt-0.5 text-3xs">Drag leads here to move</span>
                                </div>
                            @endif
                        </div>

                        @if ($column['count'] > $column['cards']->count())
                            <a href="{{ request()->fullUrlWithQuery(['show' => [...(array) request('show'), $status->id => $column['limit'] + 50]]) }}"
                               class="mt-2 block rounded-lg px-2 py-1.5 text-center text-xs font-semibold text-indigo-600 hover:bg-white">
                                Show more ({{ $column['count'] - $column['cards']->count() }} more)
                            </a>
                        @endif
                        @can('create', App\Models\Lead::class)
                            <a href="{{ route('leads.create') }}" class="mt-2 flex w-full items-center justify-center gap-1 rounded-lg border border-transparent px-2 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-200 hover:bg-white hover:text-indigo-600">
                                <x-lucide-plus class="size-3.25" aria-hidden="true" /><span>Add Lead</span>
                            </a>
                        @endcan
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>
