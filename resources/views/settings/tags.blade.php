@php
    $paletteKey = fn (?string $hex) => collect(config('crm.palette'))->search(fn ($c) => $c['hex'] === strtolower((string) $hex)) ?: 'slate';
@endphp

<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">System Tags</h2>
        @if ($tags->isEmpty())
            <x-empty-state title="No tags yet" icon="tag">Create your first tag below.</x-empty-state>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5">
                        <x-badge :color="$tag->color">{{ $tag->name }}</x-badge>
                        <span class="text-3xs text-slate-500">{{ $tag->leads_count }}</span>
                        <button type="button" x-data="modalTrigger" data-modal="tag-{{ $tag->id }}" x-on:click="open" class="text-slate-400 hover:text-indigo-600" title="Edit tag">
                            <span class="sr-only">Edit tag {{ $tag->name }}</span><x-lucide-edit class="size-3" aria-hidden="true" />
                        </button>
                        <x-confirm-dialog trigger="" trigger-variant="ghost" trigger-size="sm" trigger-icon="trash-2"
                                          class="[&>button]:p-0 [&>button]:text-slate-400 [&>button:hover]:bg-transparent [&>button:hover]:text-rose-600"
                                          :action="route('settings.tags.destroy', $tag)" method="DELETE"
                                          title="Delete the tag “{{ $tag->name }}”?" confirm="Delete Tag" loading="Deleting…"
                                          trigger-label="Delete tag {{ $tag->name }}">
                            @if ($tag->leads_count)
                                {{ $tag->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $tag->leads_count) }} will lose this tag. The leads themselves are not changed.
                            @else
                                No leads use this tag.
                            @endif
                        </x-confirm-dialog>
                    </div>

                    @php($bag = 'tag'.$tag->id)
                    <x-modal name="tag-{{ $tag->id }}" title="Edit Tag" :subtitle="$tag->name" size="sm" :open="$errors->getBag($bag)->any()">
                        <form id="tag-form-{{ $tag->id }}" method="POST" action="{{ route('settings.tags.update', $tag) }}" class="space-y-3.5" novalidate>
                            @csrf @method('PUT')
                            <x-input name="name" label="Tag Name" :bag="$bag" :id="'tag'.$tag->id.'-name'" :value="$tag->name" required />
                            <x-select name="color" label="Color" :bag="$bag" :id="'tag'.$tag->id.'-color'" :options="$palette" :value="$paletteKey($tag->color)" required />
                        </form>
                        <x-slot:footer>
                            <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
                            <x-button form="tag-form-{{ $tag->id }}">Save Tag</x-button>
                        </x-slot:footer>
                    </x-modal>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
        <h3 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Create New Tag</h3>
        <form method="POST" action="{{ route('settings.tags.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-start" novalidate>
            @csrf
            <div class="flex-1"><x-input name="name" label="Tag Name" bag="tag" id="new-tag-name" placeholder="e.g. Q4 Priority, High Net Worth" required /></div>
            <div class="sm:w-40"><x-select name="color" label="Color" bag="tag" id="new-tag-color" :options="$palette" value="blue" required /></div>
            <x-button icon="plus" class="sm:mt-5">Create Tag</x-button>
        </form>
    </div>
</x-settings-layout>
