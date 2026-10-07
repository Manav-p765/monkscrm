{{--
    The only way to show money in a view (never format amounts by hand).
    Amount is in minor units (cents/paise).

    <x-money :amount="$lead->deal_value" :currency="$lead->currency" />
    <x-money :totals="\App\Support\Money::totals($visibleLeads)" />   renders "$12,400 · ₹3,50,000"
--}}
@props(['amount' => null, 'currency' => null, 'totals' => null, 'empty' => '—'])

@php
    $text = $totals !== null
        ? \App\Support\Money::formatTotals($totals)
        : ($amount !== null && $currency !== null ? \App\Support\Money::format((int) $amount, $currency) : '');
@endphp

<span {{ $attributes->class('tabular-nums whitespace-nowrap') }}>{{ $text !== '' ? $text : $empty }}</span>
