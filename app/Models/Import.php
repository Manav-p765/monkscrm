<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One import run. The uploaded file itself is never kept after processing.
 */
class Import extends Model
{
    /**
     * user_id is set by the controller, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'original_filename',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'imported_rows',
        'updated_rows',
        'skipped_rows',
        'duplicate_strategy',
        'column_mapping',
        'error_report',
    ];

    /** Statuses after which the staged rows are no longer needed. */
    public const FINISHED_STATUSES = ['completed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'error_report' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // No cron on our hosting: every new import cleans up abandoned staging rows.
        static::creating(fn () => ImportRow::purgeStale());

        // Staged rows hold contact details; drop them as soon as the import is done.
        static::saved(function (Import $import) {
            if ($import->wasChanged('status') && in_array($import->status, self::FINISHED_STATUSES, true)) {
                $import->rows()->delete();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
