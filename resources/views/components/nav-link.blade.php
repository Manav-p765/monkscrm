@props(['href', 'active' => false])

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'block rounded-md px-3 py-2',
       'bg-blue-50 font-medium text-blue-800' => $active,
       'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $active,
   ]) }}>
    {{ $slot }}
</a>
