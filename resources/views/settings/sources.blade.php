<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div>
            <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Marketing &amp; Ingestion Sources</h2>
            <p class="mt-0.5 text-xs text-slate-500">Drag to reorder, or use the arrows. Sources are never deleted: a lead's original source always stays.</p>
        </div>

        <div x-data="sortable" data-reorder-url="{{ route('settings.sources.reorder') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($sources as $source)
                @php($isDefault = $source->id === $defaultSourceId)
                <div draggable="true" data-id="{{ $source->id }}" x-on:dragstart="start" x-on:dragover="over" x-on:drop="drop" x-on:dragend="end"
                     class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-lucide-grip-vertical class="size-3.5 shrink-0 cursor-grab text-slate-300" aria-hidden="true" />
                        <div class="flex flex-col">
                            <form method="POST" action="{{ route('settings.sources.move', $source) }}">@csrf<input type="hidden" name="direction" value="up">
                                <button type="submit" @disabled($loop->first) class="p-0.5 text-slate-400 hover:text-slate-700 disabled:opacity-20"><span class="sr-only">Move {{ $source->name }} up</span><x-lucide-arrow-up class="size-3" aria-hidden="true" /></button>
                            </form>
                            <form method="POST" action="{{ route('settings.sources.move', $source) }}">@csrf<input type="hidden" name="direction" value="down">
                                <button type="submit" @disabled($loop->last) class="p-0.5 text-slate-400 hover:text-slate-700 disabled:opacity-20"><span class="sr-only">Move {{ $source->name }} down</span><x-lucide-arrow-down class="size-3" aria-hidden="true" /></button>
                            </form>
                        </div>
                        <div class="min-w-0">
                            <span class="block truncate text-xs font-semibold text-slate-800">{{ $source->name }}</span>
                            <span class="block text-3xs text-slate-500">
                                {{ $source->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $source->leads_count) }}@if ($isDefault) · <span class="font-semibold text-blue-700">Default for new leads</span>@endif
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        @if (! $isDefault && $source->is_active)
                            <form method="POST" action="{{ route('settings.sources.default', $source) }}">@csrf<x-button variant="text" size="sm" :loading="false">Make default</x-button></form>
                        @endif
                        <form method="POST" action="{{ route('settings.sources.update', $source) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="name" value="{{ $source->name }}">
                            <input type="hidden" name="is_active" value="{{ $source->is_active ? '0' : '1' }}">
                            <button type="submit" title="{{ $source->is_active ? 'Turn off' : 'Turn on' }} {{ $source->name }}" @class([
                                'rounded px-2 py-0.5 text-3xs font-bold',
                                'bg-emerald-100 text-emerald-800' => $source->is_active,
                                'bg-slate-200 text-slate-600' => ! $source->is_active,
                            ])>{{ $source->is_active ? 'Active' : 'Disabled' }}</button>
                        </form>
                        <button type="button" x-data="modalTrigger" data-modal="source-{{ $source->id }}" x-on:click="open" class="rounded p-1 text-slate-400 hover:text-indigo-600">
                            <span class="sr-only">Rename {{ $source->name }}</span><x-lucide-edit class="size-3.25" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @foreach ($sources as $source)
        @php($bag = 'source'.$source->id)
        <x-modal name="source-{{ $source->id }}" title="Rename Source" :subtitle="$source->name" size="sm" :open="$errors->getBag($bag)->any()">
            <form id="source-form-{{ $source->id }}" method="POST" action="{{ route('settings.sources.update', $source) }}" class="space-y-3.5" novalidate>
                @csrf @method('PUT')
                <x-input name="name" label="Source Name" :bag="$bag" :id="'src'.$source->id.'-name'" :value="$source->name" required />
                <input type="hidden" name="is_active" value="0">
                <x-checkbox name="is_active" label="Active" :bag="$bag" :id="'src'.$source->id.'-active'" :checked="$source->is_active" />
            </form>
            <x-slot:footer>
                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                <x-button form="source-form-{{ $source->id }}">Save Source</x-button>
            </x-slot:footer>
        </x-modal>
    @endforeach

    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <h3 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Add New Lead Source</h3>
        <form method="POST" action="{{ route('settings.sources.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-start" novalidate>
            @csrf
            <div class="flex-1"><x-input name="name" label="Source Name" bag="source" id="new-source-name" placeholder="e.g. Podcast Sponsorship" required /></div>
            <x-button icon="plus" class="sm:mt-5">Add Source</x-button>
        </form>
    </div>
</x-settings-layout>
