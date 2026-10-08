{{-- Header search dropdown (prototype Navbar). Server-rendered fragment for the live search. --}}
<div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 p-2 text-2xs text-slate-500">
    <span class="font-medium">Found {{ $leads->count() }} matching {{ \Illuminate\Support\Str::plural('lead', $leads->count()) }}</span>
    <span>Enter for all results · Esc to dismiss</span>
</div>
@if ($leads->isEmpty())
    <div class="p-6 text-center text-xs text-slate-500">No matching leads found for “{{ $term }}”</div>
@else
    <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
        @foreach ($leads as $lead)
            <a href="{{ route('leads.show', $lead) }}" class="group flex w-full items-center justify-between p-3 text-left transition hover:bg-slate-50 focus:bg-slate-50">
                <div class="min-w-0 pr-3">
                    <div class="flex items-center gap-2">
                        <span class="truncate text-xs font-semibold text-slate-900 group-hover:text-indigo-600">{{ $lead->displayName() }}</span>
                        <span class="font-mono text-3xs text-slate-500">{{ $lead->reference }}</span>
                        <x-badge :color="$lead->status->color" :dot="false" class="text-3xs">{{ $lead->status->name }}</x-badge>
                    </div>
                    <div class="mt-1 flex items-center gap-3 text-2xs text-slate-500">
                        @if ($lead->company)<span class="flex items-center gap-1 truncate"><x-lucide-building class="size-2.75 text-slate-400" aria-hidden="true" />{{ $lead->company }}</span>@endif
                        @if ($lead->email)<span class="flex items-center gap-1 truncate"><x-lucide-mail class="size-2.75 text-slate-400" aria-hidden="true" />{{ $lead->email }}</span>@endif
                        @if ($lead->phone)<span class="flex items-center gap-1 truncate"><x-lucide-phone class="size-2.75 text-slate-400" aria-hidden="true" />{{ $lead->phone }}</span>@endif
                    </div>
                </div>
                <div class="shrink-0 text-right">
                    @if ($lead->deal_value !== null)<x-money :amount="$lead->deal_value" :currency="$lead->currency" class="text-xs font-bold text-slate-800" />@endif
                    <x-lucide-arrow-right class="mt-1 ml-auto size-3.5 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-indigo-600" aria-hidden="true" />
                </div>
            </a>
        @endforeach
    </div>
@endif
