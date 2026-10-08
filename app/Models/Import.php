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
        'processed_rows',
        'options',
        'error_rows',
        'completed_at',
        'undone_at',
    ];

    /** Statuses after which the staged rows are no longer needed. */
    public const FINISHED_STATUSES = ['completed', 'cancelled'];

    /** "Undo import" is possible for this long after it completes. */
    public const UNDO_HOURS = 24;

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'error_report' => 'array',
            'options' => 'array',
            'error_rows' => 'encrypted:array',
            'completed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function canBeUndone(): bool
    {
        return $this->status === 'completed' && $this->undone_at === null && $this->completed_at
            && $this->completed_at->gt(now()->subHours(self::UNDO_HOURS)) && $this->imported_rows > 0;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
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
