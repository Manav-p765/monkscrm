<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /** Cached read (App\Support\Settings). Change settings only through Settings::update(). */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }

    protected static function booted(): void
    {
        // Seeders and tests write rows directly; never serve a stale cache afterwards.
        static::saved(fn () => Settings::flush());
        static::deleted(fn () => Settings::flush());
    }
}
