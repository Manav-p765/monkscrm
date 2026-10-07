<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * Reads config('crm.permissions'): the one list of who may do what. Gates are generated from it,
 * policies call allows(), and the Settings permissions table renders it, so they cannot drift apart.
 * Entries with a `setting` describe the default; the setting can widen it for sales (see the policies).
 */
class Permissions
{
    /** @return list<array{key: string, label: string, roles: list<string>, setting?: string, gate?: bool}> */
    public static function all(): array
    {
        return config('crm.permissions');
    }

    /** @return list<string> role values */
    public static function rolesFor(string $key): array
    {
        foreach (self::all() as $permission) {
            if ($permission['key'] === $key) {
                return $permission['roles'];
            }
        }

        throw new InvalidArgumentException("Unknown permission [{$key}].");
    }

    public static function allows(User $user, string $key): bool
    {
        return $user->is_active && in_array($user->role->value, self::rolesFor($key), true);
    }

    /** @return list<string> keys that are registered as gates */
    public static function gates(): array
    {
        return collect(self::all())->where('gate', true)->pluck('key')->all();
    }
}
