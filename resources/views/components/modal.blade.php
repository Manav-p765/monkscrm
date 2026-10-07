{{--
    Prototype modal (Log Interaction, Schedule Follow-Up, Invite & Create Team Account …), built on
    native <dialog> (focus trap + Escape for free).

    <x-button type="button" x-data="modalTrigger" data-modal="invite" x-on:click="open">Invite</x-button>
    <x-modal name="invite" title="Invite & Create Team Account" subtitle="…" :open="$errors->invite->any()">
        …form fields…
        <x-slot:footer>
            <x-button type="button" variant="ghost" x-on:click="close">Cancel</x-button>
            <x-button form="invite-form">Create & Send Invite</x-button>
        </x-slot:footer>
    </x-modal>

    size: sm | md (default) | lg | xl | 3xl.  open: start open (e.g. after a validation error).
--}}
@props(['name', 'title', 'subtitle' => null, 'size' => 'md', 'open' => false, 'footer' => null])

@php
    $width = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '3xl' => 'max-w-3xl'][$size] ?? 'max-w-md';
    $titleId = 'modal-'.$name.'-title';
@endphp

<div x-data="modal" data-name="{{ $name }}" data-open="{{ $open ? 'true' : 'false' }}" x-on:open-modal.window="handleOpen">
    <dialog x-ref="dialog" aria-labelledby="{{ $titleId }}"
            {{ $attributes->class(['m-auto w-[calc(100%-2rem)] rounded-2xl bg-white p-6 text-slate-900 shadow-2xl', $width]) }}>
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <h2 id="{{ $titleId }}" class="text-sm font-bold text-slate-900">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="text-xs text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" x-on:click="close" class="rounded text-slate-400 hover:text-slate-600">
                <span class="sr-only">Close</span>
                <x-lucide-x class="size-4" aria-hidden="true" />
            </button>
        </div>

        <div class="space-y-3.5 pt-4">
            {{ $slot }}
        </div>

        @if ($footer?->isNotEmpty())
            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">{{ $footer }}</div>
        @endif
    </dialog>
</div>
