<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The one way to do case-insensitive partial text search (see CLAUDE.md).
 *
 *   User::query()->search($request->string('q'), ['name', 'email'])->paginate(25);
 *
 * Compiles to ILIKE on PostgreSQL and LIKE on SQLite (tests), via the query builder's
 * whereLike(caseSensitive: false). The term is always a bound parameter; % and _ typed
 * by the user are matched literally. $columns must come from code, never from input.
 */
trait Searchable
{
    /**
     * @param  list<string>  $columns
     */
    public function scopeSearch(Builder $query, ?string $term, array $columns): void
    {
        $term = trim((string) $term);

        if ($term === '' || $columns === []) {
            return;
        }

        $pattern = '%'.static::escapeLike($term, $query->getConnection()->getDriverName()).'%';

        $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhereLike($column, $pattern);
            }
        });
    }

    /**
     * Escape LIKE wildcards. PostgreSQL (and MySQL) use backslash as the default LIKE escape
     * character; SQLite has no default escape character, so there wildcards stay as typed.
     */
    public static function escapeLike(string $term, string $driver): string
    {
        if ($driver === 'sqlite') {
            return $term;
        }

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
