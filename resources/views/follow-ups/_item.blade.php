{{--
    One follow-up with its actions. In the lead panel it is a card; on the Follow-Ups page ($showLead)
    it is a row of the queue list (prototype TasksFollowUpsView), with the lead name + link.
--}}
@php
    $pending = $fu->status === \App\Enums\FollowUpStatus::Pending;
    $overdue = $pending && $fu->due_at->isPast();
    $today = $pending && ! $overdue && \App\Support\Dates::isToday($fu->due_at);
    $canManage = auth()->user()->can('manageFollowUp', [\App\Models\Lead::class, $fu]);
    $row = $showLead ?? false;
@endphp
<div @class([
    'p-4 transition hover:bg-slate-50/70 sm:p-5' => $row,
    'bg-rose-50/20' => $row && $overdue,
    'rounded-xl border p-3.5 transition' => ! $row,
    'border-slate-200 bg-slate-50 opacity-70' => ! $row && ! $pending,
    'border-rose-200 bg-rose-50/50' => ! $row && $overdue,
    'border-slate-200 bg-white' => ! $row && $pending && ! $overdue,
])>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-2xs font-bold tracking-wider text-indigo-700 uppercase">{{ $fu->type->label() }}</span>
                @if ($showLead ?? false)
                    <a href="{{ route('leads.show', $fu->lead) }}?tab=followups" class="truncate text-xs font-bold text-slate-900 hover:text-indigo-600">{{ $fu->lead->displayName() }}</a>
                    @if ($fu->lead->company)<span class="flex items-center gap-1 text-2xs text-slate-500"><x-lucide-building class="size-2.75" aria-hidden="true" />{{ $fu->lead->company }}</span>@endif
                @endif
                @if ($fu->status === \App\Enums\FollowUpStatus::Completed)
                    <x-badge tone="green">Completed</x-badge>
                @elseif ($fu->status === \App\Enums\FollowUpStatus::Cancelled)
                    <x-badge>Cancelled</x-badge>
                @elseif ($overdue)
                    <x-badge tone="red">Overdue</x-badge>
                @elseif ($today)
                    <x-badge tone="amber">Due Today</x-badge>
                @endif
            </div>
            <p @class(['mt-1 text-xs font-medium', 'text-slate-500 line-through' => ! $pending, 'text-slate-700' => $pending])>{{ $fu->notes ?: 'Scheduled '.strtolower($fu->type->label()).' touchpoint' }}</p>
            @if ($fu->outcome)<p class="mt-1 text-2xs text-slate-600"><span class="font-semibold">Outcome:</span> {{ $fu->outcome }}</p>@endif
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-2xs text-slate-500">
                <span @class(['flex items-center gap-1', 'font-bold text-rose-600' => $overdue, 'font-bold text-amber-700' => $today])>
                    <x-lucide-clock class="size-2.75" aria-hidden="true" />Due <x-date :value="$fu->due_at" />
                </span>
                <span>·</span>
                <span>Assigned to {{ $fu->assignee?->name ?? 'nobody' }}</span>
            </div>
        </div>

        @if ($canManage)
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                @if ($pending)
                    <x-dropdown width="w-72">
                        <x-slot:trigger><x-button type="button" variant="success" size="sm" icon="check">Mark Done</x-button></x-slot:trigger>
                        <form method="POST" action="{{ route('follow-ups.update', $fu) }}" class="space-y-2 p-3 [&_button]:w-auto [&_button]:hover:bg-transparent">
                            @csrf @method('PATCH')
                            <input type="hidden" name="action" value="complete">
                            <x-input name="outcome" :id="'fu-outcome-'.$fu->id" label="Outcome (optional)" placeholder="e.g. Agreed to a demo" bag="followUp" />
                            <x-button size="sm" variant="success" loading="Saving…">Mark Done</x-button>
                        </form>
                    </x-dropdown>
                    <x-dropdown width="w-64">
                        <x-slot:trigger><x-button type="button" variant="secondary" size="sm">Reschedule</x-button></x-slot:trigger>
                        <form method="POST" action="{{ route('follow-ups.update', $fu) }}" class="space-y-2 p-3 [&_button]:w-auto [&_button]:hover:bg-transparent">
                            @csrf @method('PATCH')
                            <input type="hidden" name="action" value="reschedule">
                            <x-input name="due_at" type="datetime-local" :id="'fu-resched-'.$fu->id" label="New due date & time" bag="followUp" :value="\App\Support\Dates::toInput($fu->due_at)" required />
                            <x-button size="sm" loading="Saving…">Save</x-button>
                        </form>
                    </x-dropdown>
                    <form method="POST" action="{{ route('follow-ups.update', $fu) }}">
                        @csrf @method('PATCH')<input type="hidden" name="action" value="cancel">
                        <x-button variant="ghost" size="sm" loading="Cancelling…">Cancel</x-button>
                    </form>
                @else
                    <form method="POST" action="{{ route('follow-ups.update', $fu) }}">
                        @csrf @method('PATCH')<input type="hidden" name="action" value="reopen">
                        <x-button variant="secondary" size="sm" icon="rotate-ccw" loading="Reopening…">Reopen</x-button>
                    </form>
                @endif
                @if ($showLead ?? false)
                    <x-button variant="secondary" size="sm" :href="route('leads.show', $fu->lead).'?tab=followups'">View Lead <x-lucide-arrow-up-right class="size-3.25" aria-hidden="true" /></x-button>
                @endif
            </div>
        @endif
    </div>
</div>
