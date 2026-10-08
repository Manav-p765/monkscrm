{{--
    Prototype duplicate banner. $lead is set only if the user may see the matching lead; otherwise only
    "A lead with this email/phone already exists" is shown, with no details.
--}}
@if ($found)
    <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3 px-4 text-xs text-amber-900" role="status">
        <x-lucide-alert-triangle class="mt-0.5 size-4.5 shrink-0 text-amber-600" aria-hidden="true" />
        <div>
            <span class="font-bold">Potential Duplicate Record Found: </span>
            @if ($lead)
                <span>A lead with matching {{ $on }} already exists:
                    <strong class="underline">{{ $lead->name ?? $lead->displayName() }}</strong>@if ($lead->company) at <strong>{{ $lead->company }}</strong>@endif ({{ $lead->reference }}).</span>
                <a href="{{ route('leads.show', $lead->reference) }}" class="ml-1 inline-flex items-center gap-1 font-bold text-amber-800 underline hover:text-amber-950">
                    <span>View Existing Lead</span><x-lucide-external-link class="size-2.75" aria-hidden="true" />
                </a>
            @else
                <span>A lead with this {{ $on }} already exists.</span>
            @endif
            <p class="mt-1 text-amber-800">
                {{ $canOverride ? 'You can still save it: tick “Save anyway” after checking.' : 'Saving is blocked to avoid duplicates. Ask a manager if this is a different person.' }}
            </p>
        </div>
    </div>
@endif
