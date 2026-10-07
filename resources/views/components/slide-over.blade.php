{{--
    Prototype right-hand slide-over (LeadDetailModal). It has its own URL: the page that renders it
    opens it on load, and closing it (×, Escape or the backdrop) goes to `close-href` (e.g. the list).

    <x-slide-over :close-href="route('leads.index')" label="Lead LD-000123">
        <x-slot:header>…name, actions, quick selects…</x-slot:header>
        …tabs and content…
    </x-slide-over>
--}}
@props(['closeHref', 'label', 'header' => null, 'width' => 'max-w-3xl'])

<div x-data="slideOver" data-close-href="{{ $closeHref }}">
    <dialog x-ref="dialog" aria-label="{{ $label }}" x-on:cancel="cancel" x-on:click="backdropClick"
            {{ $attributes->class(['m-0 ml-auto flex h-dvh max-h-dvh w-full flex-col overflow-hidden bg-white p-0 text-slate-900 shadow-2xl open:flex', $width]) }}>
        @if ($header?->isNotEmpty())
            <div class="relative border-b border-slate-200 bg-slate-50/70 p-6">
                {{ $header }}
            </div>
        @endif
        <div class="flex-1 overflow-y-auto">
            {{ $slot }}
        </div>
    </dialog>
</div>
