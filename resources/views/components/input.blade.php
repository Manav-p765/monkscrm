{{--
    <x-input name="phone" label="Primary Phone" type="tel" required hint="..." />
    size:   md (forms, modals) | lg (sign-in screens)
    icon:   leading Lucide icon, e.g. icon="mail"
    reveal: password field with a show/hide eye button
    bag:    error bag name (e.g. "updatePassword" for Fortify's password form)
    <x-slot:aside>: shown next to the label (e.g. a "Forgot password?" link)
    Password fields are never re-filled from old input.
--}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null, 'bag' => 'default',
    'id' => null, 'size' => 'md', 'icon' => null, 'reveal' => false, 'aside' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $current = $type === 'password' ? null : old($key, $value);
    $iconClass = $size === 'lg' ? 'left-3.5 size-4' : 'left-3 size-3.75';
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :aside="$aside" :label-hidden="$size === 'filter'">
    <div @if ($reveal) x-data="passwordReveal" @endif class="relative">
        @if ($icon)
            <x-dynamic-component :component="'lucide-'.$icon" :class="'pointer-events-none absolute top-1/2 -translate-y-1/2 text-slate-400 '.$iconClass" aria-hidden="true" />
        @endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
               @if ($reveal) x-bind:type="inputType" @endif
               @if ($current !== null) value="{{ $current }}" @endif
               @if ($required) required @endif
               @if ($error) aria-invalid="true" @endif
               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
               {{ $attributes->class(\App\View\FormStyles::control($size, (bool) $error, (bool) $icon, $reveal)) }}>
        @if ($reveal)
            <button type="button" x-on:click="toggle" x-bind:aria-pressed="shown"
                    class="absolute top-1/2 right-3.5 -translate-y-1/2 rounded text-slate-400 hover:text-slate-600">
                <span class="sr-only" x-text="label">Show password</span>
                <x-lucide-eye x-show="hidden" class="size-4" aria-hidden="true" />
                <x-lucide-eye-off x-cloak x-show="shown" class="size-4" aria-hidden="true" />
            </button>
        @endif
    </div>
</x-field>
