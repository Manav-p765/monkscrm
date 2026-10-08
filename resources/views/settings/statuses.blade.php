@php
    $paletteKey = fn (?string $hex) => collect(config('crm.palette'))->search(fn ($c) => $c['hex'] === strtolower((string) $hex)) ?: 'slate';
@endphp

<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <div>
            <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Configured Lead Stages</h2>
            <p class="mt-0.5 text-xs text-slate-500">Drag to reorder, or use the arrows. Disabled stages stay on existing leads but are hidden when picking a status. Stages are never deleted.</p>
        </div>

        <div x-data="sortable" data-reorder-url="{{ route('settings.statuses.reorder') }}"
             class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200">
            @foreach ($statuses as $status)
                @php($isDefault = $status->id === $defaultStatusId)
                <div draggable="true" data-id="{{ $status->id }}" x-on:dragstart="start" x-on:dragover="over" x-on:drop="drop" x-on:dragend="end"
                     class="flex flex-wrap items-center justify-between gap-3 p-3.5 transition hover:bg-slate-50">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-lucide-grip-vertical class="size-3.5 shrink-0 cursor-grab text-slate-300" aria-hidden="true" />
                        <div class="flex flex-col gap-0.5">
                            <form method="POST" action="{{ route('settings.statuses.move', $status) }}">
                                @csrf <input type="hidden" name="direction" value="up">
                                <button type="submit" @disabled($loop->first) class="p-0.5 text-slate-400 hover:text-slate-700 disabled:opacity-20" title="Move up">
                                    <span class="sr-only">Move {{ $status->name }} up</span><x-lucide-arrow-up class="size-3" aria-hidden="true" />
                                </button>
                            </form>
                            <form method="POST" action="{{ route('settings.statuses.move', $status) }}">
                                @csrf <input type="hidden" name="direction" value="down">
                                <button type="submit" @disabled($loop->last) class="p-0.5 text-slate-400 hover:text-slate-700 disabled:opacity-20" title="Move down">
                                    <span class="sr-only">Move {{ $status->name }} down</span><x-lucide-arrow-down class="size-3" aria-hidden="true" />
                                </button>
                            </form>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :color="$status->color">{{ $status->name }}</x-badge>
                                @if ($status->type->value === 'won')
                                    <x-badge tone="green" class="text-4xs uppercase">Won Flag</x-badge>
                                @elseif ($status->type->value === 'lost')
                                    <x-badge tone="red" class="text-4xs uppercase">Lost Flag</x-badge>
                                @endif
                                @if ($status->milestone)
                                    <x-badge tone="indigo" class="text-4xs uppercase">{{ $status->milestone->label() }}</x-badge>
                                @endif
                                @if ($isDefault)
                                    <x-badge tone="blue" class="text-4xs uppercase">Default for new leads</x-badge>
                                @endif
                                <span class="text-3xs text-slate-500">{{ $status->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $status->leads_count) }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $status->description ?: 'No description' }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if (! $isDefault && $status->is_active && $status->type->value === 'open')
                            <form method="POST" action="{{ route('settings.statuses.default', $status) }}">
                                @csrf
                                <x-button variant="text" size="sm" :loading="false">Make default</x-button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('settings.statuses.update', $status) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="name" value="{{ $status->name }}">
                            <input type="hidden" name="description" value="{{ $status->description }}">
                            <input type="hidden" name="color" value="{{ $paletteKey($status->color) }}">
                            <input type="hidden" name="type" value="{{ $status->type->value }}">
                            <input type="hidden" name="milestone" value="{{ $status->milestone?->value }}">
                            <input type="hidden" name="is_active" value="{{ $status->is_active ? '0' : '1' }}">
                            <button type="submit" title="{{ $status->is_active ? 'Disable' : 'Enable' }} {{ $status->name }}" @class([
                                'rounded border px-2 py-1 text-2xs font-semibold transition',
                                'border-emerald-200 bg-emerald-50 text-emerald-700' => $status->is_active,
                                'border-slate-200 bg-slate-100 text-slate-500' => ! $status->is_active,
                            ])>{{ $status->is_active ? 'Enabled' : 'Disabled' }}</button>
                        </form>
                        <button type="button" x-data="modalTrigger" data-modal="status-{{ $status->id }}" x-on:click="open"
                                class="rounded p-1.5 text-slate-400 transition hover:text-indigo-600" title="Edit {{ $status->name }}">
                            <span class="sr-only">Edit {{ $status->name }}</span>
                            <x-lucide-edit class="size-3.25" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @foreach ($statuses as $status)
        @php($bag = 'status'.$status->id)
        <x-modal name="status-{{ $status->id }}" title="Edit Status: {{ $status->name }}" :open="$errors->getBag($bag)->any()">
            <form id="status-form-{{ $status->id }}" method="POST" action="{{ route('settings.statuses.update', $status) }}" class="space-y-3.5" novalidate>
                @csrf @method('PUT')
                <x-input name="name" label="Stage Label" :bag="$bag" :id="'s'.$status->id.'-name'" :value="$status->name" required />
                <x-input name="description" label="Description" :bag="$bag" :id="'s'.$status->id.'-desc'" :value="$status->description" placeholder="Stage milestone objective…" />
                <div class="grid grid-cols-3 gap-3">
                    <x-select name="color" label="Accent Color" :bag="$bag" :id="'s'.$status->id.'-color'" :options="$palette" :value="$paletteKey($status->color)" required />
                    <x-select name="type" label="Type" :bag="$bag" :id="'s'.$status->id.'-type'" :options="$types" :value="$status->type" required />
                    <x-select name="milestone" label="Milestone" :bag="$bag" :id="'s'.$status->id.'-milestone'" :options="$milestones" :value="$status->milestone" placeholder="None" />
                </div>
                <input type="hidden" name="is_active" value="0">
                <x-checkbox name="is_active" label="Enabled" :bag="$bag" :id="'s'.$status->id.'-active'" :checked="$status->is_active"
                            hint="Disabled stages stay on existing leads but can't be picked." />
            </form>
            <x-slot:footer>
                <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                <x-button form="status-form-{{ $status->id }}">Save Status</x-button>
            </x-slot:footer>
        </x-modal>
    @endforeach

    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <h3 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Add New Custom Status</h3>
        <form method="POST" action="{{ route('settings.statuses.store') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-6" novalidate>
            @csrf
            <div class="sm:col-span-2"><x-input name="name" label="Stage Label" bag="status" id="new-status-name" placeholder="e.g. Under Contract Review" required /></div>
            <x-select name="color" label="Accent Color" bag="status" id="new-status-color" :options="$palette" value="blue" required />
            <x-select name="type" label="Type" bag="status" id="new-status-type" :options="$types" value="open" required />
            <x-select name="milestone" label="Milestone" bag="status" id="new-status-milestone" :options="$milestones" placeholder="None" />
            <div class="sm:col-span-4"><x-input name="description" label="Description" bag="status" id="new-status-desc" placeholder="Stage milestone objective…" /></div>
            <div class="flex items-end sm:col-span-2"><x-button icon="plus" class="w-full">Add Status</x-button></div>
        </form>
    </div>
</x-settings-layout>
