{{-- Panel modals (prototype "Log Interaction" and "Schedule Follow-Up"). Inside the slide-over so they stay interactive. --}}
@php
    $nowLocal = now(\App\Support\Dates::zone());
    $tomorrow10 = $nowLocal->copy()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
    $followUpTypes = collect(\App\Enums\FollowUpType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]);
    $activityTypes = ['call' => 'Phone Call', 'email' => 'Email', 'meeting' => 'Video Meeting / Demo', 'sms' => 'SMS Text', 'other' => 'Other'];
    $openActivity = $errors->activity->any() ? old('type', 'other') : null;
@endphp

@can('logActivity', $lead)
    @foreach (['call', 'email', 'meeting', 'other'] as $preset)
        <x-modal name="log-{{ $preset }}" title="Log Interaction with {{ $lead->displayName() }}" :open="$openActivity !== null && ($openActivity === $preset || ($preset === 'other' && ! in_array($openActivity, ['call', 'email', 'meeting'], true)))">
            <form id="activity-form-{{ $preset }}" method="POST" action="{{ route('leads.activities.store', $lead) }}" class="space-y-3" novalidate
                  x-data="moreDetails" data-open="{{ old('schedule_follow_up') ? 'true' : 'false' }}">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <x-select name="type" :id="'act-type-'.$preset" bag="activity" label="Activity Type" :options="$activityTypes" :value="$preset === 'other' ? 'call' : $preset" required />
                    <x-input name="occurred_at" :id="'act-at-'.$preset" bag="activity" type="datetime-local" label="When" :value="$nowLocal->format('Y-m-d\TH:i')" hint="India time" />
                </div>
                <x-textarea name="description" :id="'act-desc-'.$preset" bag="activity" label="Notes / Description" rows="3" placeholder="Discussed pricing model, next steps…" required />
                <x-input name="outcome" :id="'act-outcome-'.$preset" bag="activity" label="Outcome" placeholder="e.g. Qualified, Agreed to send NDA, Left voicemail" />
                @can('scheduleFollowUp', $lead)
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <input type="checkbox" name="schedule_follow_up" value="1" x-on:change="toggle" @checked(old('schedule_follow_up')) class="size-4 rounded border-slate-300 accent-indigo-600">
                        Also schedule a follow-up for me
                    </label>
                    <div x-cloak x-show="open" class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <x-select name="follow_up_type" :id="'act-fu-type-'.$preset" bag="activity" label="Action Type" :options="$followUpTypes" value="call" />
                        <x-input name="follow_up_due_at" :id="'act-fu-due-'.$preset" bag="activity" type="datetime-local" label="Due" :value="$tomorrow10" />
                    </div>
                @endcan
            </form>
            <x-slot:footer>
                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                <x-button form="activity-form-{{ $preset }}" loading="Saving…">Save Activity</x-button>
            </x-slot:footer>
        </x-modal>
    @endforeach
@endcan

@can('scheduleFollowUp', $lead)
    <x-modal name="schedule-follow-up" title="Schedule Follow-Up" :subtitle="$lead->displayName()" :open="$errors->followUp->any()">
        <form id="follow-up-form" method="POST" action="{{ route('leads.follow-ups.store', $lead) }}" class="space-y-3" novalidate>
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <x-select name="type" id="fu-type" bag="followUp" label="Action Type" :options="$followUpTypes" value="call" required />
                <x-select name="assigned_to" id="fu-assignee" bag="followUp" label="Assigned To" :options="$followUpAssignees" :value="auth()->id()" required />
            </div>
            <x-input name="due_at" id="fu-due" bag="followUp" type="datetime-local" label="Due Date & Time" :value="$tomorrow10" hint="India time" required />
            <x-textarea name="notes" id="fu-notes" bag="followUp" label="Follow-Up Instructions / Note" rows="2" placeholder="Check if procurement approved proposal…" />
        </form>
        <x-slot:footer>
            <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
            <x-button form="follow-up-form" loading="Scheduling…">Set Follow-Up</x-button>
        </x-slot:footer>
    </x-modal>
@endcan
