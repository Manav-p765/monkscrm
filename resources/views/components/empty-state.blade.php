{{--
    Prototype empty state (LeadListView "No leads match your criteria"). Every list needs one:
    say what to do next and offer the button for it.

    <x-empty-state title="No leads yet" icon="users-round">
        Add your first lead or import a CSV.
        <x-slot:actions><x-button :href="route('leads.create')" icon="plus">Add Lead</x-button></x-slot:actions>
    </x-empty-state>

    Inside a table: <tr><td colspan="9"><x-empty-state .../></td></tr>
    tone: neutral (default) | success (green check, e.g. "You are all caught up")
--}}
@props(['title', 'icon' => 'search', 'tone' => 'neutral'])

<div {{ $attributes->class('py-16 text-center') }}>
    <div class="mx-auto max-w-sm space-y-3">
        @if ($tone === 'success')
            <x-lucide-check-circle-2 class="mx-auto size-9 text-emerald-500" aria-hidden="true" />
        @else
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <x-dynamic-component :component="'lucide-'.$icon" class="size-5" aria-hidden="true" />
            </div>
        @endif
        <p class="text-sm font-semibold text-slate-800">{{ $title }}</p>
        @if ($slot->isNotEmpty())
            <p class="text-xs text-slate-500">{{ $slot }}</p>
        @endif
        @isset($actions)
            <div class="flex flex-wrap items-center justify-center gap-3 pt-1">{{ $actions }}</div>
        @endisset
    </div>
</div>
