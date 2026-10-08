<?php

namespace App\Support;

/**
 * Settings a production app must have before it serves a single request.
 * The app refuses to boot in production while any problem is listed (AppServiceProvider).
 * Messages name the setting, never its value.
 */
final class ProductionGuard
{
    /**
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is not set';
        }

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }

        $url = (string) config('app.url');
        if ($url === '' || $url === 'http://localhost') {
            $problems[] = 'APP_URL is not set';
        } elseif (! str_starts_with(strtolower($url), 'https://')) {
            $problems[] = 'APP_URL must start with https://';
        }

        if (blank(config('database.connections.'.config('database.default').'.url'))) {
            $problems[] = 'DB_URL is not set';
        }

        return $problems;
    }
}
