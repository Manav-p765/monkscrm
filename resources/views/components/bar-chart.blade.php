{{--
    Horizontal bars in the prototype's style (ReportsView funnel). Server-rendered SVG: CSP-safe, no JS
    chart library, no eval, no style="" (widths are SVG attributes).

    <x-bar-chart :rows="[['label' => 'New', 'count' => 12, 'aside' => '$1,200'], …]" />
    color: Tailwind fill class for the bars.
--}}
@props(['rows', 'color' => 'fill-indigo-600'])

@php
    $total = max(1, (int) collect($rows)->sum('count'));
@endphp

<div {{ $attributes->class('space-y-3') }}>
    @foreach ($rows as $row)
        {{-- The bar is as long as the percentage shown next to it (share of the total, or the given pct). --}}
        @php $pct = (int) ($row['pct'] ?? round(100 * $row['count'] / $total)); @endphp
        <div class="space-y-1">
            <div class="flex items-center justify-between gap-3 text-xs font-semibold">
                <span class="truncate text-slate-800">{{ $row['label'] }}</span>
                <span class="flex shrink-0 items-center gap-3">
                    <span class="text-slate-500">{{ number_format($row['count']) }}</span>
                    @isset($row['aside'])<span class="font-bold text-slate-900">{{ $row['aside'] }}</span>@endisset
                    <span class="w-10 text-right text-slate-500">{{ $pct }}%</span>
                </span>
            </div>
            <svg class="block h-2.5 w-full" viewBox="0 0 100 2.5" preserveAspectRatio="none" role="img" aria-label="{{ $row['label'] }}: {{ $row['count'] }}">
                <rect width="100" height="2.5" rx="1.25" class="fill-slate-100" />
                <rect width="{{ max(2, $pct) }}" height="2.5" rx="1.25" class="{{ $color }}" />
            </svg>
        </div>
    @endforeach
</div>
