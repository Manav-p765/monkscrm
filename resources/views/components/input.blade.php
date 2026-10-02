{{--
    <x-input name="phone" label="Phone number" type="tel" required hint="..." />
    bag: error bag name (e.g. "updatePassword" for Fortify's password form).
    Password fields are never re-filled from old input.
--}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null, 'bag' => 'default', 'id' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $current = $type === 'password' ? null : old($key, $value);
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @if ($current !== null) value="{{ $current }}" @endif
           @if ($required) required @endif
           @if ($error) aria-invalid="true" @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->class([
               'block w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-1 focus:outline-none',
               'border-slate-300 focus:border-blue-600 focus:ring-blue-600' => ! $error,
               'border-red-600 focus:border-red-600 focus:ring-red-600' => $error,
           ]) }}>
</x-field>
