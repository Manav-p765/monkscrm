<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'error_report' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
