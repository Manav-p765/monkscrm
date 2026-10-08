<?php

namespace App\Support;

/**
 * CSV output that is safe to open in a spreadsheet: any cell starting with = + - @ (or a tab /
 * carriage return) gets a leading apostrophe, so it can't run as a formula (CSV injection).
 */
class Csv
{
    public static function cell(mixed $value): string
    {
        $value = $value === null ? '' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
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
