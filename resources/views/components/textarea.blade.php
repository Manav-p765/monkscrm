{{--
    <x-textarea name="description" label="Notes about this lead" rows="4" />
--}}
@props(['name', 'label', 'value' => null, 'required' => false, 'hint' => null, 'bag' => 'default', 'id' => null, 'rows' => 4])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              @if ($required) required @endif
              @if ($error) aria-invalid="true" @endif
              @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
              {{ $attributes->class([
                  'block w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-1 focus:outline-none',
                  'border-slate-300 focus:border-blue-600 focus:ring-blue-600' => ! $error,
                  'border-red-600 focus:border-red-600 focus:ring-red-600' => $error,
              ]) }}>{{ old($key, $value) }}</textarea>
</x-field>
