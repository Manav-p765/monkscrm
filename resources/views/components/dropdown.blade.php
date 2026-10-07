{{--
    Prototype dropdown menu (bulk "Change Status ▾", alerts, search results, user menu).
    Put plain <a> links or <form><button> elements in the slot; they are styled here.

    <x-dropdown>
        <x-slot:trigger><x-button type="button" variant="dark" icon="user-check">Assign Rep</x-button></x-slot:trigger>
        <a href="…">Priya Patel</a>
    </x-dropdown>

    align: right (default) | left.  width: Tailwind width class.  up: open above the trigger.
--}}
@props(['trigger', 'align' => 'right', 'width' => 'w-48', 'up' => false])

<div x-data="dropdown" x-on:keydown.escape="close" x-on:click.outside="close" class="relative inline-block">
    <div x-on:click="toggle" x-bind:aria-expanded="open">{{ $trigger }}</div>
    <div x-cloak x-show="open" x-transition.opacity.duration.100ms
         {{ $attributes->class([
             'absolute z-30 rounded-xl border border-slate-200 bg-white py-1 text-slate-900 shadow-xl',
             $align === 'left' ? 'left-0' : 'right-0',
             $up ? 'bottom-full mb-2' : 'top-full mt-2',
             $width,
             '[&_a]:flex [&_a]:w-full [&_a]:items-center [&_a]:gap-2 [&_a]:px-3 [&_a]:py-1.5 [&_a]:text-left [&_a]:text-xs [&_a:hover]:bg-slate-100',
             '[&_button]:flex [&_button]:w-full [&_button]:items-center [&_button]:gap-2 [&_button]:px-3 [&_button]:py-1.5 [&_button]:text-left [&_button]:text-xs [&_button:hover]:bg-slate-100',
         ]) }}>
        {{ $slot }}
    </div>
</div>
