{{--
    Forms show only the fields people fill most often; everything else goes in here.
    Pass :open="true" when a hidden field has a validation error so the user can see it:
    <x-more-details :open="$errors->hasAny(['address_line1', 'website'])"> ... </x-more-details>
--}}
@props(['open' => false, 'label' => 'More details'])

@php($panelId = 'more-details-'.\Illuminate\Support\Str::random(6))

<div x-data="moreDetails" data-open="{{ $open ? 'true' : 'false' }}" {{ $attributes }}>
    <button type="button" x-on:click="toggle" x-bind:aria-expanded="open" aria-controls="{{ $panelId }}"
            class="rounded-sm font-medium text-blue-700 underline-offset-2 hover:underline">
        <span x-show="closed">Show {{ strtolower($label) }}</span>
        <span x-cloak x-show="open">Hide {{ strtolower($label) }}</span>
    </button>
    <div id="{{ $panelId }}" x-cloak x-show="open" class="mt-5 space-y-5">
        {{ $slot }}
    </div>
</div>
