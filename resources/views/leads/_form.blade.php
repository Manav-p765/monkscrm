{{-- Create / edit lead (prototype LeadFormModal). Own URL: /leads/create, /leads/{reference}/edit. --}}
@php
    $isEdit = $lead->exists;
    $closeHref = $isEdit ? route('leads.show', [$lead, ...request()->query()]) : route('leads.index', request()->query());
    $selectedTags = old('tags', $isEdit ? $lead->tags->pluck('id')->all() : []);
    $currency = $isEdit ? $lead->currency : $lookups['defaultCurrency'];
    $currencies = collect($lookups['currencies']);
    if ($isEdit && ! $currencies->contains($lead->currency)) {
        $currencies->push($lead->currency); // a since-disabled currency stays valid on this lead
    }
    $statusOptions = $lookups['statuses']->filter(fn ($s) => $s->is_active || ($isEdit && $s->id === $lead->lead_status_id))->pluck('name', 'id');
    $sourceOptions = $lookups['sources']->filter(fn ($s) => $s->is_active || ($isEdit && $s->id === $lead->lead_source_id))->pluck('name', 'id');
    $assignees = $lookups['assignees'];
    if ($isEdit && $lead->assignee && ! $assignees->has($lead->assigned_to)) {
        $assignees = collect([$lead->assigned_to => $lead->assignee->name])->union($assignees);
    }
    $section = 'pt-4 space-y-3 border-t border-slate-100';
    $sectionLabel = 'text-xs font-bold tracking-wider text-slate-500 uppercase';
@endphp

<x-modal name="lead-form" :title="$isEdit ? 'Edit Lead: '.$lead->displayName() : 'Create New Lead'"
         :subtitle="$isEdit ? 'Update lead details, contact attributes, and pipeline parameters' : 'Fast data entry with automatic duplicate detection'"
         size="3xl" open :close-href="$closeHref" class="max-h-[90vh] overflow-y-auto">
    <form id="lead-form" method="POST" action="{{ $isEdit ? route('leads.update', $lead) : route('leads.store') }}"
          x-data="duplicateCheck" data-url="{{ route('leads.duplicate-check') }}" data-ignore="{{ $isEdit ? $lead->reference : '' }}"
          class="space-y-6" novalidate>
        @csrf
        @if ($isEdit) @method('PUT') @endif

        {{-- Duplicate warning: live (as you type) and after a blocked save --}}
        <div x-ref="warning" aria-live="polite">
            @if ($duplicate)
                @include('leads._duplicate-warning', [
                    'found' => true, 'on' => $duplicate['on'], 'canOverride' => $duplicate['can_override'],
                    'lead' => $duplicate['reference'] ? (object) ['reference' => $duplicate['reference'], 'name' => $duplicate['name'], 'company' => $duplicate['company']] : null,
                ])
            @endif
        </div>
        @if ($duplicate && $duplicate['can_override'])
            <x-checkbox name="confirm_duplicate" label="Save anyway" hint="I've checked the existing lead and still want to save this one. This is recorded in the audit log." />
        @endif

        <div class="space-y-3">
            <div class="{{ $sectionLabel }}">1. Basic Information</div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-input name="first_name" label="First Name" :value="$lead->first_name" placeholder="e.g. Elena" hint="First name or company is required." />
                <x-input name="last_name" label="Last Name" :value="$lead->last_name" placeholder="e.g. Vance" />
                <x-input name="company" label="Company / Organization" :value="$lead->company" placeholder="e.g. Sterling Logistics Inc." />
                <x-input name="job_title" label="Job Title" :value="$lead->job_title" placeholder="e.g. Chief Technology Officer" />
            </div>
        </div>

        <div class="{{ $section }}">
            <div class="{{ $sectionLabel }}">2. Contact Information (At least Email or Phone required)</div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-input name="email" type="email" label="Primary Email" :value="$lead->email" placeholder="name@company.com" x-on:input.debounce.400ms="check" />
                <x-input name="phone" type="tel" label="Primary Phone" :value="$lead->phone" placeholder="+91 98765 43210" x-on:input.debounce.400ms="check" />
                <x-input name="secondary_email" type="email" label="Secondary Email" :value="$lead->secondary_email" placeholder="alt.contact@company.com" />
                <x-input name="secondary_phone" type="tel" label="Secondary Phone" :value="$lead->secondary_phone" placeholder="+91 91234 56789" />
                <div class="sm:col-span-2"><x-input name="website" type="url" label="Company Website" :value="$lead->website" placeholder="https://company.example.com" /></div>
            </div>
        </div>

        <div class="{{ $section }}">
            <div class="{{ $sectionLabel }}">3. CRM &amp; Pipeline Attributes</div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-select name="lead_status_id" label="Lead Status" :options="$statusOptions" :value="$isEdit ? $lead->lead_status_id : $lookups['defaultStatusId']" required />
                <x-select name="lead_source_id" label="Lead Source" :options="$sourceOptions" :value="$isEdit ? $lead->lead_source_id : $lookups['defaultSourceId']" required />
                <x-select name="priority" label="Priority" :options="collect($lookups['priorities'])->mapWithKeys(fn ($p) => [$p->value => $p->label()])" :value="$isEdit ? $lead->priority : 'medium'" placeholder="No priority" />
                <x-select name="assigned_to" label="Assigned To" :options="$assignees" :value="$isEdit ? $lead->assigned_to : auth()->id()"
                          :placeholder="$lookups['canAssignOthers'] ? 'Unassigned' : null" :hint="$lookups['canAssignOthers'] ? null : 'You can assign leads to yourself.'" />
                <div class="sm:col-span-2">
                    <div class="mb-1 text-xs font-semibold text-slate-700">Deal Value</div>
                    <div class="flex gap-2">
                        <div class="w-28 shrink-0">
                            <x-select name="currency" label="Currency" label-hidden :options="$currencies->mapWithKeys(fn ($c) => [$c->value => $c->symbol().' '.$c->value])" :value="$currency" />
                        </div>
                        <div class="flex-1">
                            <x-input name="deal_value" label="Deal value amount" label-hidden inputmode="decimal"
                                     :value="\App\Support\Money::toInput($lead->deal_value, $lead->currency)" placeholder="25000" />
                        </div>
                    </div>
                </div>
                <div class="sm:col-span-3">
                    <fieldset>
                        <legend class="mb-1 text-xs font-semibold text-slate-700">Tags &amp; Categories</legend>
                        <div class="flex flex-wrap gap-2 pt-1">
                            @foreach ($lookups['tags'] as $tag)
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="tags[]" value="{{ $tag->id }}" class="peer sr-only" @checked(in_array($tag->id, $selectedTags))>
                                    <span class="inline-block rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-600 transition peer-checked:border-indigo-600 peer-checked:bg-indigo-600 peer-checked:font-medium peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600 hover:bg-slate-100">{{ $tag->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>

        <div class="{{ $section }}">
            <div class="{{ $sectionLabel }}">4. Address &amp; Location</div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><x-input name="address_line1" label="Street Address" :value="$lead->address_line1" placeholder="100 MG Road, Suite 400" /></div>
                <x-input name="address_line2" label="Address Line 2" :value="$lead->address_line2" />
                <x-input name="city" label="City" :value="$lead->city" placeholder="Bengaluru" />
                <x-input name="state" label="State / Province" :value="$lead->state" placeholder="Karnataka" />
                <x-input name="postal_code" label="Postal / ZIP Code" :value="$lead->postal_code" placeholder="560001" />
                <x-input name="country" label="Country" :value="$lead->country ?? ($isEdit ? null : 'India')" placeholder="India" />
            </div>
        </div>

        <div class="{{ $section }}">
            <div class="{{ $sectionLabel }}">5. Description &amp; Initial Notes</div>
            <x-textarea name="description" label="Background Description" rows="2" :value="$lead->description" placeholder="Key requirements, company size, tech stack, timeline…" />
            @unless ($isEdit)
                <x-textarea name="initial_note" label="Initial Internal Note (optional)" rows="2" placeholder="Record immediate notes from first outreach or call…" />
            @endunless
        </div>
    </form>

    <x-slot:footer>
        <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
        <x-button form="lead-form" loading="Saving…">{{ $isEdit ? 'Save Changes' : 'Create Lead' }}</x-button>
    </x-slot:footer>
</x-modal>
