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
                @php($canAssign = $canEdit && ($lookups['canAssignOthers'] || $lead->assigned_to !== auth()->id()))
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

        {{-- Quick actions (prototype toolbar). Logging and follow-ups arrive with lead activity (step 4). --}}
        <div class="mt-4 flex items-center gap-2 overflow-x-auto pb-1">
            @foreach ([['phone-call', 'text-emerald-600', 'Log Call'], ['send', 'text-blue-600', 'Log Email'], ['video', 'text-purple-600', 'Log Meeting']] as [$icon, $tone, $label])
                <button type="button" disabled title="Coming soon" class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-slate-700 opacity-60 shadow-2xs">
                    <x-dynamic-component :component="'lucide-'.$icon" class="size-3.25 {{ $tone }}" aria-hidden="true" /><span>{{ $label }}</span>
                </button>
            @endforeach
            <button type="button" disabled title="Coming soon" class="flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-indigo-700 opacity-60 shadow-2xs">
                <x-lucide-clock class="size-3.25 text-indigo-600" aria-hidden="true" /><span>Schedule Follow-Up</span>
            </button>
        </div>
    </x-slot:header>

    <x-tabs variant="underline" active="timeline" label="Lead sections" :tabs="[
        'timeline' => ['label' => 'Activity Timeline', 'count' => $lead->activities->count()],
        'notes' => ['label' => 'Internal Notes', 'count' => $lead->notes()->count()],
        'followups' => ['label' => 'Follow-Ups', 'count' => $lead->followUps()->count()],
        'details' => ['label' => 'Contact Details'],
    ]" class="[&>[role=tablist]]:bg-white">
        <div class="p-6">
            <x-tab-panel name="timeline" class="space-y-6">
                <span class="text-xs font-bold text-slate-700">Chronological Lead History</span>
                @if ($lead->activities->isEmpty())
                    <x-empty-state title="No activity yet" icon="activity">Status changes, assignments and logged calls will appear here.</x-empty-state>
                @else
                    <div class="relative space-y-6 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2.5 before:w-0.5 before:bg-slate-200">
                        @foreach ($lead->activities as $activity)
                            @php([$icon, $tone] = $activityIcon($activity))
                            <div class="relative">
                                <div class="absolute top-0.5 -left-6 flex size-5 items-center justify-center rounded-full border border-slate-300 bg-white shadow-2xs">
                                    <x-dynamic-component :component="'lucide-'.$icon" class="size-3.25 {{ $tone }}" aria-hidden="true" />
                                </div>
                                <div class="rounded-xl border border-slate-200/80 bg-slate-50 p-3.5">
                                    <div class="mb-1 flex items-center justify-between text-2xs text-slate-500">
                                        <span class="font-semibold text-slate-800">{{ $activity->user?->name ?? 'System' }}</span>
                                        <x-date :value="$activity->occurred_at" />
                                    </div>
                                    <p class="text-xs leading-relaxed font-medium text-slate-700">{{ $activity->description }}</p>
                                    @if ($activity->outcome)
                                        <div class="mt-2 rounded border border-slate-200 bg-white p-2 text-2xs text-slate-600"><span class="font-semibold text-slate-700">Outcome:</span> {{ $activity->outcome }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-tab-panel>

            <x-tab-panel name="notes" x-cloak>
                <x-empty-state title="Internal notes are coming soon" icon="file-text">Writing timestamped notes on a lead arrives with the next update.</x-empty-state>
            </x-tab-panel>

            <x-tab-panel name="followups" x-cloak>
                <x-empty-state title="Follow-ups are coming soon" icon="clock">Scheduling calls, meetings and check-ins arrives with the next update.</x-empty-state>
            </x-tab-panel>

            <x-tab-panel name="details" x-cloak class="space-y-6">
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
                            <div class="sm:col-span-2"><span class="block text-3xs font-semibold text-slate-500 uppercase">Website</span><a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">{{ $lead->website }}</a></div>
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
</x-slide-over>
