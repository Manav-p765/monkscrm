<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reordering for sortable lookup tables (statuses, sources): drag-and-drop sends the full order,
 * the keyboard Move up/down buttons swap with a neighbour. sort_order is rewritten as 10, 20, 30…
 */
class Reorder
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids  every row's id, in the new order
     */
    public static function apply(string $model, array $ids): bool
    {
        $existing = $model::query()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($existing !== $given) {
            return false;
        }

        DB::transaction(function () use ($model, $ids) {
            foreach (array_values($ids) as $i => $id) {
                $model::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return true;
    }

    /**
     * @param  'up'|'down'  $direction
     */
    public static function move(Model $row, string $direction): void
    {
        $ids = $row->newQuery()->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $index = array_search((int) $row->getKey(), $ids, true);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! isset($ids[$swap])) {
            return;
        }

        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
        self::apply($row::class, $ids);
    }
}
