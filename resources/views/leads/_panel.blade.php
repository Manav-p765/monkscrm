{{-- Lead slide-over (prototype LeadDetailModal). Own URL: /leads/{reference}. --}}
@php
    $closeHref = route('leads.index', request()->query());
    $canEdit = auth()->user()->can('update', $lead);
    $activityIcon = fn ($a) => match ($a->meta['event'] ?? $a->type->value) {
        'status_changed' => ['check-circle-2', 'text-indigo-600'],
        'assigned' => ['user-check', 'text-cyan-600'],
        'created' => ['sparkles', 'text-indigo-600'],
        'call' => ['phone-call', 'text-emerald-600'],
        'email' => ['send', 'text-blue-600'],
        'meeting' => ['video', 'text-purple-600'],
        'note' => ['file-text', 'text-amber-600'],
        default => ['activity', 'text-slate-500'],
    };
@endphp

<x-slide-over :close-href="$closeHref" label="Lead {{ $lead->reference }}">
    <x-slot:header>
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 class="truncate text-xl font-bold text-slate-900">{{ $lead->displayName() }}</h2>
                    <span class="rounded border border-slate-200 bg-white px-2 py-0.5 font-mono text-xs text-slate-500">{{ $lead->reference }}</span>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('leads.priority', $lead) }}" x-data="autoSubmit" x-on:change="submit">
                            @csrf @method('PATCH')
                            <label for="panel-priority" class="sr-only">Priority</label>
                            <select id="panel-priority" name="priority" class="rounded border border-slate-200 bg-white px-2 py-0.5 text-2xs font-semibold text-slate-700 focus:outline-none">
                                <option value="">No priority</option>
                                @foreach ($lookups['priorities'] as $p)
                                    <option value="{{ $p->value }}" @selected($lead->priority === $p)>{{ $p->label() }}</option>
                                @endforeach
                            </select>
                        </form>
                    @else
                        <x-priority :value="$lead->priority" />
                    @endif
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span class="font-semibold text-slate-800">{{ $lead->company ?: 'Individual' }}</span>
                    @if ($lead->job_title)<span>· {{ $lead->job_title }}</span>@endif
                    @if ($lead->deal_value !== null)
                        <span>·</span><x-money :amount="$lead->deal_value" :currency="$lead->currency" class="font-bold text-emerald-700" />
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($canEdit)
                    <a href="{{ route('leads.edit', [$lead, ...request()->query()]) }}" title="Edit lead details" class="rounded-lg border border-slate-200 p-2 text-slate-600 transition hover:bg-white hover:text-slate-900">
                        <span class="sr-only">Edit lead details</span><x-lucide-edit class="size-3.75" aria-hidden="true" />
                    </a>
                @endif
                @can('archive', $lead)
                    <x-confirm-dialog trigger="" trigger-label="Archive {{ $lead->displayName() }}" trigger-icon="archive" trigger-variant="ghost"
                                      class="[&>button]:rounded-lg [&>button]:border [&>button]:border-rose-200 [&>button]:p-2 [&>button]:text-rose-600 [&>button:hover]:bg-rose-50"
                                      :action="route('leads.destroy', $lead)" method="DELETE"
                                      title="Archive {{ $lead->reference }} ({{ $lead->displayName() }})?" confirm="Archive Lead" loading="Archiving…">
                        The lead will be hidden from lists and the pipeline. An admin can restore it from Archived leads.
                    </x-confirm-dialog>
                @endcan
                <a href="{{ $closeHref }}" title="Close" class="rounded-lg border border-slate-200 p-2 text-slate-400 transition hover:bg-white hover:text-slate-700">
                    <span class="sr-only">Close</span><x-lucide-x class="size-4" aria-hidden="true" />
                </a>
            </div>
        </div>

        {{-- Quick stage and assignee selectors --}}
        <div class="mt-4 grid grid-cols-1 gap-3 border-t border-slate-200/80 pt-4 sm:grid-cols-2">
            <form method="POST" action="{{ route('leads.status', $lead) }}" x-data="autoSubmit" x-on:change="submit"
                  class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
                @csrf @method('PATCH')
                <input type="hidden" name="via" value="panel">
                <label for="panel-status" class="text-2xs font-semibold tracking-wider text-slate-500 uppercase">Stage:</label>
                <select id="panel-status" name="lead_status_id" @disabled(! $canEdit) class="flex-1 bg-transparent text-xs font-bold text-slate-900 focus:outline-none">
                    @foreach ($lookups['statuses'] as $status)
                        @if ($status->is_active || $status->id === $lead->lead_status_id)
                            <option value="{{ $status->id }}" @selected($status->id === $lead->lead_status_id)>{{ $status->name }}{{ $status->is_active ? '' : ' (disabled)' }}</option>
                        @endif
                    @endforeach
                </select>
            </form>
            <form method="POST" action="{{ route('leads.assignee', $lead) }}" x-data="autoSubmit" x-on:change="submit"
                  class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
                @csrf @method('PATCH')
                <label for="panel-assignee" class="text-2xs font-semibold tracking-wider text-slate-500 uppercase">Assigned To:</label>
                @php $canAssign = $canEdit && ($lookups['canAssignOthers'] || $lead->assigned_to !== auth()->id()); @endphp
                <select id="panel-assignee" name="assigned_to" @disabled(! $canAssign) class="flex-1 truncate bg-transparent text-xs font-medium text-slate-900 focus:outline-none">
                    @if ($lookups['canAssignOthers'] || ! $lead->assigned_to)
                        <option value="">Unassigned</option>
                    @endif
                    @if ($lead->assignee && ! $lookups['assignees']->has($lead->assigned_to))
                        <option value="{{ $lead->assigned_to }}" selected>{{ $lead->assignee->name }}</option>
                    @endif
                    @foreach ($lookups['assignees'] as $id => $name)
                        <option value="{{ $id }}" @selected($id === $lead->assigned_to)>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        {{-- Quick actions (prototype toolbar) --}}
        @can('logActivity', $lead)
            <div class="mt-4 flex items-center gap-2 overflow-x-auto pb-1">
                @foreach ([['call', 'phone-call', 'text-emerald-600', 'Log Call'], ['email', 'send', 'text-blue-600', 'Log Email'], ['meeting', 'video', 'text-purple-600', 'Log Meeting']] as [$type, $icon, $tone, $label])
                    <button type="button" x-data="modalTrigger" data-modal="log-{{ $type }}" x-on:click="open"
                            class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-slate-700 shadow-2xs transition hover:bg-slate-50">
                        <x-dynamic-component :component="'lucide-'.$icon" class="size-3.25 {{ $tone }}" aria-hidden="true" /><span>{{ $label }}</span>
                    </button>
                @endforeach
                @can('scheduleFollowUp', $lead)
                    <button type="button" x-data="modalTrigger" data-modal="schedule-follow-up" x-on:click="open"
                            class="flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-indigo-700 shadow-2xs transition hover:bg-indigo-100">
                        <x-lucide-clock class="size-3.25 text-indigo-600" aria-hidden="true" /><span>Schedule Follow-Up</span>
                    </button>
                @endcan
            </div>
        @endcan
    </x-slot:header>

    @php
        $canLog = auth()->user()->can('logActivity', $lead);
        $canSchedule = auth()->user()->can('scheduleFollowUp', $lead);
        $tabUrl = fn (array $q) => route('leads.show', [$lead, ...$q]);
    @endphp
    <x-tabs variant="underline" :active="$panelTab" label="Lead sections" :tabs="[
        'timeline' => ['label' => 'Activity Timeline', 'count' => $timeline->total()],
        'notes' => ['label' => 'Internal Notes', 'count' => $lead->notes->count()],
        'followups' => ['label' => 'Follow-Ups', 'count' => $lead->followUps->where('status', \App\Enums\FollowUpStatus::Pending)->count()],
        'details' => ['label' => 'Contact Details'],
    ]" class="*:[[role=tablist]]:bg-white">
        <div class="p-6">
            {{-- Timeline --}}
            <x-tab-panel name="timeline" :active="$panelTab === 'timeline'" class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-bold text-slate-700">Chronological Lead History</span>
                    @if ($canLog)
                        <button type="button" x-data="modalTrigger" data-modal="log-other" x-on:click="open" class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800">
                            <x-lucide-plus class="size-3.25" aria-hidden="true" /><span>Log Interaction</span>
                        </button>
                    @endif
                </div>
                <nav aria-label="Timeline filter" class="flex flex-wrap gap-1.5">
                    @foreach (\App\Support\LeadTimeline::FILTERS as $key => $label)
                        @php $on = request('tl', 'all') === $key; @endphp
                        <a href="{{ $tabUrl(['tab' => 'timeline', 'tl' => $key]) }}" @if ($on) aria-current="page" @endif
                           @class(['rounded-lg px-2.5 py-1 text-2xs font-medium transition', 'bg-slate-900 text-white' => $on, 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' => ! $on])>{{ $label }}</a>
                    @endforeach
                </nav>
                @if ($timeline->isEmpty())
                    <x-empty-state title="Nothing here yet" icon="activity">Logged calls, emails, meetings, notes and changes will appear here.</x-empty-state>
                @else
                    <div class="relative space-y-6 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2.5 before:w-0.5 before:bg-slate-200">
                        @foreach ($timeline as $item)
                            @php
                                [$icon, $tone] = match ($item['kind']) {
                                    'call' => ['phone-call', 'text-emerald-600'], 'email' => ['send', 'text-blue-600'],
                                    'meeting' => ['video', 'text-purple-600'], 'note' => ['file-text', 'text-amber-600'],
                                    'follow_up' => ['clock', 'text-rose-600'],
                                    default => match ($item['event']) { 'status_changed' => ['check-circle-2', 'text-indigo-600'], 'assigned' => ['user-check', 'text-cyan-600'], 'created' => ['sparkles', 'text-indigo-600'], default => ['activity', 'text-slate-500'] },
                                };
                            @endphp
                            <div class="relative">
                                <div class="absolute top-0.5 -left-6 flex size-5 items-center justify-center rounded-full border border-slate-300 bg-white shadow-2xs">
                                    <x-dynamic-component :component="'lucide-'.$icon" class="size-3.25 {{ $tone }}" aria-hidden="true" />
                                </div>
                                <div class="rounded-xl border border-slate-200/80 bg-slate-50 p-3.5">
                                    <div class="mb-1 flex items-center justify-between text-2xs text-slate-500">
                                        <span class="font-semibold text-slate-800">{{ $item['user'] ?? 'System' }}</span>
                                        <x-date :value="$item['at']" />
                                    </div>
                                    <p class="text-xs leading-relaxed font-medium whitespace-pre-line text-slate-700">{{ $item['text'] }}</p>
                                    @if ($item['outcome'])
                                        <div class="mt-2 rounded border border-slate-200 bg-white p-2 text-2xs text-slate-600"><span class="font-semibold text-slate-700">Outcome:</span> {{ $item['outcome'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($timeline->hasPages())
                        <div class="text-2xs">{{ $timeline->links('partials.pagination') }}</div>
                    @endif
                @endif
            </x-tab-panel>

            {{-- Notes --}}
            <x-tab-panel name="notes" :active="$panelTab === 'notes'" class="space-y-6">
                @if ($canLog)
                    <form method="POST" action="{{ route('leads.notes.store', $lead) }}" class="space-y-2" novalidate>
                        @csrf
                        <x-textarea name="body" id="new-note" bag="note" label="Add Timestamped Internal Note" rows="3"
                                    placeholder="Record insights, customer objections, or internal handoff notes…" />
                        <div class="flex justify-end"><x-button size="sm" loading="Posting…">Post Note</x-button></div>
                    </form>
                @endif
                @if ($lead->notes->isEmpty())
                    <x-empty-state title="No notes recorded yet" icon="file-text">{{ $canLog ? 'Use the box above to write the first note.' : 'Notes written on this lead will appear here.' }}</x-empty-state>
                @else
                    <div class="space-y-3 divide-y divide-slate-100">
                        @foreach ($lead->notes as $note)
                            <div class="pt-3 first:pt-0">
                                <div class="mb-1 flex items-center justify-between gap-2 text-2xs text-slate-500">
                                    <span class="font-semibold text-slate-800">{{ $note->user?->name ?? 'Former user' }}</span>
                                    <span class="flex items-center gap-2">
                                        <x-date :value="$note->created_at" relative />
                                        @if (auth()->user()->can('updateNote', [\App\Models\Lead::class, $note]))
                                            <button type="button" x-data="modalTrigger" data-modal="note-{{ $note->id }}" x-on:click="open" class="font-semibold text-indigo-600 hover:text-indigo-800">Edit</button>
                                        @endif
                                        @can('deleteNote', [\App\Models\Lead::class, $note])
                                            <x-confirm-dialog trigger="Delete" trigger-variant="text" trigger-size="sm" class="[&>button]:text-rose-600"
                                                              :action="route('notes.destroy', $note)" method="DELETE"
                                                              title="Delete this note by {{ $note->user?->name ?? 'a former user' }}?" confirm="Delete Note" loading="Deleting…">
                                                It disappears from the lead. The deletion is recorded in the audit log.
                                            </x-confirm-dialog>
                                        @endcan
                                    </span>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs whitespace-pre-wrap text-slate-700">{{ $note->body }}</div>
                            </div>
                            @if (auth()->user()->can('updateNote', [\App\Models\Lead::class, $note]))
                                <x-modal name="note-{{ $note->id }}" title="Edit Note" subtitle="You can edit your note for 15 minutes after writing it">
                                    <form id="note-form-{{ $note->id }}" method="POST" action="{{ route('notes.update', $note) }}" novalidate>
                                        @csrf @method('PUT')
                                        <x-textarea name="body" :id="'note-body-'.$note->id" bag="note" label="Note" rows="4" :value="$note->body" required />
                                    </form>
                                    <x-slot:footer>
                                        <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                                        <x-button form="note-form-{{ $note->id }}">Save Note</x-button>
                                    </x-slot:footer>
                                </x-modal>
                            @endif
                        @endforeach
                    </div>
                @endif
            </x-tab-panel>

            {{-- Follow-ups --}}
            <x-tab-panel name="followups" :active="$panelTab === 'followups'" class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-bold text-slate-900">Follow-Up Schedule</h3>
                        <p class="text-2xs text-slate-500">Scheduled touchpoints and commitments</p>
                    </div>
                    @if ($canSchedule)
                        <x-button type="button" size="sm" icon="plus" x-data="modalTrigger" data-modal="schedule-follow-up" x-on:click="open">Schedule Action</x-button>
                    @endif
                </div>
                @if ($lead->followUps->isEmpty())
                    <x-empty-state title="No follow-ups scheduled for this lead" icon="clock" />
                @else
                    <div class="space-y-3">
                        @foreach ($lead->followUps as $fu)
                            @include('follow-ups._item', ['fu' => $fu, 'showLead' => false])
                        @endforeach
                    </div>
                @endif
            </x-tab-panel>

            <x-tab-panel name="details" :active="$panelTab === 'details'" class="space-y-6">
                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h4 class="text-xs font-bold tracking-wider text-slate-800 uppercase">Contact Information</h4>
                    <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Primary Email</span>
                            @if ($lead->email)<a href="mailto:{{ $lead->email }}" class="font-medium text-indigo-600 hover:underline">{{ $lead->email }}</a>@else — @endif</div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Primary Phone</span>
                            @if ($lead->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $lead->phone) }}" class="font-medium text-slate-800 hover:text-indigo-600">{{ $lead->phone }}</a>@else — @endif</div>
                        @if ($lead->secondary_email)
                            <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Secondary Email</span><a href="mailto:{{ $lead->secondary_email }}" class="text-indigo-600 hover:underline">{{ $lead->secondary_email }}</a></div>
                        @endif
                        @if ($lead->secondary_phone)
                            <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Secondary Phone</span><a href="tel:{{ preg_replace('/[^0-9+]/', '', $lead->secondary_phone) }}" class="text-slate-800">{{ $lead->secondary_phone }}</a></div>
                        @endif
                        @if ($lead->website)
                            <div class="sm:col-span-2"><span class="block text-3xs font-semibold text-slate-500 uppercase">Website</span>@if (preg_match('#^https?://#i', $lead->website))<a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">{{ $lead->website }}</a>@else{{ $lead->website }}@endif</div>
                        @endif
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h4 class="text-xs font-bold tracking-wider text-slate-800 uppercase">Physical Address</h4>
                    <div class="text-xs text-slate-700">
                        @if ($lead->address_line1 || $lead->city || $lead->country)
                            @if ($lead->address_line1)<div>{{ $lead->address_line1 }}</div>@endif
                            @if ($lead->address_line2)<div>{{ $lead->address_line2 }}</div>@endif
                            <div>{{ collect([$lead->city, $lead->state, $lead->postal_code])->filter()->implode(', ') }}</div>
                            @if ($lead->country)<div>{{ $lead->country }}</div>@endif
                        @else
                            <span class="text-slate-500 italic">No address recorded</span>
                        @endif
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h4 class="text-xs font-bold tracking-wider text-slate-800 uppercase">CRM Meta &amp; Tracking</h4>
                    <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-3">
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Lead Source</span><span class="font-medium text-slate-800">{{ $lead->source?->name ?? '—' }}</span></div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Original Source</span><span class="font-medium text-slate-800">{{ $lead->originalSource?->name ?? '—' }}</span></div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Deal Value</span><x-money :amount="$lead->deal_value" :currency="$lead->currency" class="font-medium text-slate-800" /></div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Created</span><x-date :value="$lead->created_at" /> <span class="text-slate-500">by {{ $lead->creator?->name ?? '—' }}</span></div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Last Contacted</span><x-date :value="$lead->last_contacted_at" /></div>
                        <div><span class="block text-3xs font-semibold text-slate-500 uppercase">Next Follow-Up</span><x-date :value="$lead->next_follow_up_at" /></div>
                        <div class="col-span-2 sm:col-span-3"><span class="block text-3xs font-semibold text-slate-500 uppercase">Tags</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @forelse ($lead->tags as $tag)
                                    <x-badge :color="$tag->color" :dot="false">{{ $tag->name }}</x-badge>
                                @empty
                                    <span class="text-slate-500">No tags</span>
                                @endforelse
                            </div>
                        </div>
                        @if ($lead->description)
                            <div class="col-span-2 sm:col-span-3"><span class="block text-3xs font-semibold text-slate-500 uppercase">Background Description</span><p class="whitespace-pre-line text-slate-700">{{ $lead->description }}</p></div>
                        @endif
                    </div>
                </div>
            </x-tab-panel>
        </div>
    </x-tabs>
    @include('leads._panel-modals')
</x-slide-over>
