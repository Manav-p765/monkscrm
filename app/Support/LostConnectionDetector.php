<?php

namespace App\Support;

use Illuminate\Database\LostConnectionDetector as BaseDetector;
use Illuminate\Support\Str;
use Throwable;

/**
 * Laravel's lost-connection list plus what Neon sends when it suspends an idle compute or recycles a
 * connection. Laravel then reconnects and retries the query once, but only outside a transaction
 * (inside one, the error is thrown, so a half-done transaction is never replayed).
 */
class LostConnectionDetector extends BaseDetector
{
    public const EXTRA_MESSAGES = [
        'terminating connection due to administrator command', // SQLSTATE 57P01: compute suspended / restarted
        'SSL SYSCALL error: EOF detected',                     // any prefix (Laravel only knows one exact form)
        'could not receive data from server',
        'server closed the connection',
    ];

    public function causedByLostConnection(Throwable $e): bool
    {
        return parent::causedByLostConnection($e) || Str::contains($e->getMessage(), self::EXTRA_MESSAGES);
    }
}
