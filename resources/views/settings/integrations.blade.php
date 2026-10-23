<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Website Forms &amp; Integrations</h2>
                <p class="mt-0.5 text-xs text-slate-500">Leads sent from your websites' forms (or Zapier / Make) arrive here as new leads. Each form gets its own address and secret key.</p>
            </div>
            <x-button type="button" icon="plus" x-data="modalTrigger" data-modal="integration-new" x-on:click="open">Add Website Form</x-button>
        </div>

        @if ($integrations->isEmpty())
            <x-empty-state title="No website forms connected yet" icon="plug">
                Add a form for each website you run, then paste its address into the form's webhook settings.
                <x-slot:actions>
                    <x-button type="button" icon="plus" x-data="modalTrigger" data-modal="integration-new" x-on:click="open">Add Website Form</x-button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($integrations as $integration)
                    <a href="{{ route('settings.integrations.show', $integration) }}"
                       class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 transition hover:border-indigo-200 hover:bg-white">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                <x-lucide-globe class="size-4" aria-hidden="true" />
                            </div>
                            <div class="min-w-0">
                                <span class="block truncate text-xs font-semibold text-slate-800">{{ $integration->name }}</span>
                                <span class="block text-3xs text-slate-500">
                                    {{ $integration->type->label() }} · {{ $integration->source?->name ?? 'Default source' }} · {{ $integration->assignee?->name ?? 'Unassigned' }}
                                </span>
                                <span class="block text-3xs text-slate-500">
                                    {{ $integration->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $integration->leads_count) }} ·
                                    @if ($integration->last_received_at) last post <x-date :value="$integration->last_received_at" relative /> @else nothing received yet @endif
                                </span>
                            </div>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-2 py-0.5 text-3xs font-bold',
                            'bg-emerald-100 text-emerald-800' => $integration->is_active,
                            'bg-slate-200 text-slate-600' => ! $integration->is_active,
                        ])>{{ $integration->is_active ? 'Active' : 'Off' }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 text-xs text-slate-600 shadow-2xs">
        <h3 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Coming next</h3>
        <p class="mt-1">Meta (Facebook &amp; Instagram) Lead Ads and Google Ads lead forms will connect here too. LinkedIn Lead Gen forms can be sent in today through Zapier or Make, using a website form's address and secret key.</p>
    </div>

    <x-modal name="integration-new" title="Add Website Form" subtitle="You'll get the address and secret key next" :open="$errors->getBag('integration')->any()">
        <form id="integration-new-form" method="POST" action="{{ route('settings.integrations.store') }}" novalidate>
            @csrf
            @include('settings._integration-form', ['bag' => 'integration', 'prefix' => 'int-new'])
        </form>
        <x-slot:footer>
            <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
            <x-button form="integration-new-form" loading="Adding…">Add Form</x-button>
        </x-slot:footer>
    </x-modal>
</x-settings-layout>
