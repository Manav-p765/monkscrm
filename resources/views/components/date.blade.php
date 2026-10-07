{{--
    The only way to show a date in a view (never call ->format() in Blade). Shown in IST.
    <x-date :value="$user->last_login_at" />            "2 Oct 2026, 3:45 pm"
    <x-date :value="$note->created_at" relative />     "2 hours ago" (full date in the tooltip)
    <x-date :value="$followUp->due_at" day />          "2 Oct 2026"
--}}
@props(['value' => null, 'relative' => false, 'day' => false, 'empty' => '—'])

@if ($value)
    <time datetime="{{ \App\Support\Dates::iso($value) }}"
          @if ($relative) title="{{ \App\Support\Dates::format($value) }}" @endif
          {{ $attributes }}>{{ $relative ? \App\Support\Dates::relative($value) : ($day ? \App\Support\Dates::day($value) : \App\Support\Dates::format($value)) }}</time>
@else
    <span {{ $attributes }}>{{ $empty }}</span>
@endif
