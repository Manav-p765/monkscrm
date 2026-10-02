{{--
    Every list needs one. Say what to do next and offer the button for it.

    <x-empty-state title="No leads yet">
        Add your first lead or import a CSV.
        <x-slot:actions><x-button :href="route('leads.create')">Add lead</x-button></x-slot:actions>
    </x-empty-state>
--}}
@props(['title'])

<div {{ $attributes->class('py-12') }}>
    <p class="text-base font-medium text-slate-900">{{ $title }}</p>
    @if ($slot->isNotEmpty())
        <p class="mt-1 max-w-prose text-slate-600">{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="mt-5 flex flex-wrap items-center gap-4">{{ $actions }}</div>
    @endisset
</div>
