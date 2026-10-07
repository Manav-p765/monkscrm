{{--
    <x-textarea name="description" label="Background Description" rows="2" placeholder="Key requirements…" />
--}}
@props(['name', 'label', 'value' => null, 'required' => false, 'hint' => null, 'bag' => 'default', 'id' => null, 'rows' => 3,
    'size' => 'md', 'aside' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :aside="$aside">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              @if ($required) required @endif
              @if ($error) aria-invalid="true" @endif
              @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
              {{ $attributes->class(\App\View\FormStyles::control($size, (bool) $error)) }}>{{ old($key, $value) }}</textarea>
</x-field>
