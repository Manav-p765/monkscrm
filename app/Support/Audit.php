<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way to write the audit log (CLAUDE.md rule 6). Append-only.
 *
 *     Audit::log('user.role_changed', $user, ['from' => 'sales', 'to' => 'manager']);
 *
 * Records the acting user, action, subject, IP and user agent. Any meta key containing
 * "password", "token", "secret" or "code" is dropped (at any depth), so credentials, reset/invite
 * tokens and 2FA codes can never land in the log. Log IDs/references, not lead contact details.
 */
class Audit
{
    private const SENSITIVE = ['password', 'token', 'secret', 'code'];

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): AuditLog
    {
        $request = request();
        $meta = self::clean($meta);

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    public static function clean(array $meta): array
    {
        $clean = [];

        foreach ($meta as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }
}
