<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The only place dates are formatted or parsed for people (CLAUDE.md: never format dates directly).
 * Storage is UTC; display and user input use config('crm.display_timezone') (Asia/Kolkata).
 */
class Dates
{
    public static function zone(): string
    {
        return config('crm.display_timezone');
    }

    /** "2 Oct 2026, 3:45 pm" in IST. */
    public static function format(?DateTimeInterface $value, string $empty = '—'): string
    {
        return $value ? self::local($value)->format('j M Y, g:i a') : $empty;
    }

    /** "2 Oct 2026" in IST. */
    public static function day(?DateTimeInterface $value, string $empty = '—'): string
    {
        return $value ? self::local($value)->format('j M Y') : $empty;
    }

    /** "2 hours ago", "in 3 days", "just now". */
    public static function relative(?DateTimeInterface $value, string $empty = '—'): string
    {
        if (! $value) {
            return $empty;
        }

        $local = self::local($value);

        return abs($local->diffInSeconds(now())) < 60 ? 'just now' : $local->diffForHumans();
    }

    /** ISO 8601 (UTC) for <time datetime="…">. */
    public static function iso(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->toIso8601String();
    }

    /**
     * A date/time a user typed (e.g. "2026-10-02 15:45" from <input type="datetime-local">,
     * or "2026-10-02" from <input type="date">) is IST; returns the UTC instant to store.
     */
    public static function fromInput(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, self::zone())->utc();
    }

    /** Midnight at the end of today in IST, as a UTC instant ("due today" = before this). */
    public static function endOfToday(): Carbon
    {
        return self::fromInput(now(self::zone())->toDateString())->addDay();
    }

    /** Value for an <input type="datetime-local"> showing a stored UTC time in IST. */
    public static function toInput(?DateTimeInterface $value): string
    {
        return $value ? self::local($value)->format('Y-m-d\TH:i') : '';
    }

    private static function local(DateTimeInterface $value): CarbonInterface
    {
        return CarbonImmutable::instance($value)->setTimezone(self::zone());
    }
}
