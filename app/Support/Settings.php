<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The one way to read and change settings. All rows are cached together (database cache store,
 * so it survives restarts and is shared by every instance) and the cache is cleared on any change.
 * Every change is audit-logged with its old and new value.
 */
class Settings
{
    public const CACHE_KEY = 'crm.settings';

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Save several settings; only changed values are written and audited.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> keys that changed
     */
    public static function update(array $values): array
    {
        $changed = [];

        foreach ($values as $key => $value) {
            $old = self::get($key);
            if ($old === $value) {
                continue;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            Audit::log('settings.updated', meta: ['setting' => $key, 'from' => $old, 'to' => $value]);
            $changed[] = $key;
        }

        if ($changed !== []) {
            self::flush();
        }

        return $changed;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
