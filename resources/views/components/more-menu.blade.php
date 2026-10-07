{{--
    "More" menu for secondary actions in a page header (via the layout's `more` slot).
    Put plain <a> links or <form><button> elements inside. Not for navigation.
--}}
@props(['label' => 'More'])

<x-dropdown width="w-56" {{ $attributes }}>
    <x-slot:trigger>
        <x-button type="button" variant="secondary" aria-haspopup="true">
            {{ $label }}
            <x-lucide-chevron-down class="size-3" aria-hidden="true" />
        </x-button>
    </x-slot:trigger>
    {{ $slot }}
</x-dropdown>
