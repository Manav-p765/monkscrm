<?php

namespace App\Support;

use App\Enums\Currency;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The only place money is formatted or totalled (CLAUDE.md: never format money directly).
 * Amounts are integers in minor units (cents/paise). Amounts in different currencies
 * are never added together and never converted.
 */
class Money
{
    /**
     * "$100,000", "$1,234.50", "₹1,00,000", "€99.99". Decimals are hidden when zero.
     */
    public static function format(int $minor, Currency|string $currency): string
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);
        $factor = 10 ** $currency->minorUnits();

        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $whole = intdiv($abs, $factor);
        $fraction = $abs % $factor;

        $digits = $currency === Currency::INR
            ? self::groupIndian((string) $whole)
            : number_format($whole, 0, '.', ',');

        if ($fraction > 0) {
            $digits .= '.'.str_pad((string) $fraction, $currency->minorUnits(), '0', STR_PAD_LEFT);
        }

        return $sign.$currency->symbol().$digits;
    }

    /**
     * One entry per currency, e.g. "$12,400 · ₹3,50,000". Empty string when there is nothing.
     *
     * @param  array<string, int>  $totals  currency code => minor units
     */
    public static function formatTotals(array $totals): string
    {
        return collect(Currency::cases())
            ->filter(fn (Currency $c) => array_key_exists($c->value, $totals))
            ->map(fn (Currency $c) => self::format((int) $totals[$c->value], $c))
            ->implode(' · ');
    }

    /**
     * Deal value totals per currency for an (already visibility-scoped) lead query.
     *
     * @return array<string, int> currency code => minor units, in the fixed currency order
     */
    public static function totals(Builder $leads): array
    {
        $sums = (clone $leads)
            ->toBase()
            ->reorder()
            ->whereNotNull('deal_value')
            ->selectRaw('currency, sum(deal_value) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $totals = [];
        foreach (Currency::cases() as $currency) {
            if (isset($sums[$currency->value])) {
                $totals[$currency->value] = (int) $sums[$currency->value];
            }
        }

        return $totals;
    }

    /**
     * What a person typed ("25000", "2,50,000.50", "$1,234.5") → minor units. Null for empty input.
     * Returns false when it isn't a valid non-negative amount with at most two decimals.
     */
    public static function parse(?string $input, Currency|string $currency): int|false|null
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);
        $clean = preg_replace('/[\s,]/u', '', str_replace($currency->symbol(), '', trim((string) $input)));

        if ($clean === '') {
            return null;
        }
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean)) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', $clean), 2, '');

        return (int) $whole * 10 ** $currency->minorUnits() + (int) str_pad($fraction, $currency->minorUnits(), '0');
    }

    /** Minor units → the plain number to put back in a form field ("250000.5" style, no grouping). */
    public static function toInput(?int $minor, Currency|string|null $currency): string
    {
        if ($minor === null || $currency === null) {
            return '';
        }
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);
        $factor = 10 ** $currency->minorUnits();
        $fraction = $minor % $factor;

        return intdiv($minor, $factor).($fraction ? '.'.str_pad((string) $fraction, $currency->minorUnits(), '0', STR_PAD_LEFT) : '');
    }

    /** Indian grouping: last three digits, then pairs (1234567 → 12,34,567). */
    private static function groupIndian(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }

        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $pairs = str_split(strrev($rest), 2);

        return strrev(implode(',', $pairs)).','.$last3;
    }
}
