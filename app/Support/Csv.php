<?php

namespace App\Support;

/**
 * CSV output that is safe to open in a spreadsheet: any cell starting with = + - @ (or a tab /
 * carriage return) gets a leading apostrophe, so it can't run as a formula (CSV injection).
 * Exception: phone numbers and negative numbers ("+91 98765 43210", "-500") contain only digits,
 * spaces and ( ) . - so they can't call a function; they are left as they are.
 */
class Csv
{
    public static function cell(mixed $value): string
    {
        $value = $value === null ? '' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);

        if (preg_match('/^[+\-][0-9 ().\-]*$/', $value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** Undo cell(): a value we exported and someone re-imports loses the protective apostrophe. */
    public static function unescape(string $value): string
    {
        return preg_match("/^'[=+\-@]/", $value) ? substr($value, 1) : $value;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $cells
     */
    public static function write($handle, array $cells): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $cells), ',', '"', '');
    }
}
