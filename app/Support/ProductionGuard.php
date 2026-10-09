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

        $dbUrl = (string) config('database.connections.'.config('database.default').'.url');
        if ($dbUrl === '') {
            $problems[] = 'DB_URL is not set';
        } elseif (str_contains((string) parse_url($dbUrl, PHP_URL_HOST), '-pooler.')) {
            // Neon's pooler is PgBouncer in transaction mode. PDO cleans up prepared statements with a
            // SQL DEALLOCATE that PgBouncer can't map, which aborts the surrounding transaction
            // (e.g. the login rate limiter: "25P02 current transaction is aborted").
            $problems[] = 'DB_URL must be the Neon direct connection (host without -pooler)';
        }

        return $problems;
    }
}
