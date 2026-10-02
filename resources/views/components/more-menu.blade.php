{{--
    The single "More" menu for secondary actions in a page header. Put plain <a> links or
    <form><button> elements inside; they are styled here. Not for navigation.
--}}
@props(['label' => 'More'])

<div x-data="moreMenu" x-on:keydown.escape="close" x-on:click.outside="close" class="relative">
    <x-button type="button" variant="secondary" x-on:click="toggle" x-bind:aria-expanded="open" aria-haspopup="true">
        {{ $label }}
    </x-button>
    <div x-cloak x-show="open"
         {{ $attributes->class([
             'absolute right-0 z-20 mt-2 w-56 rounded-md bg-white py-1 ring-1 ring-slate-200',
             '[&_a]:block [&_a]:px-4 [&_a]:py-2 [&_a]:text-slate-800 [&_a:hover]:bg-slate-50',
             '[&_button]:block [&_button]:w-full [&_button]:px-4 [&_button]:py-2 [&_button]:text-left [&_button]:text-slate-800 [&_button:hover]:bg-slate-50',
         ]) }}>
        {{ $slot }}
    </div>
</div>
