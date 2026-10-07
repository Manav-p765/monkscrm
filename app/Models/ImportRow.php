<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed row of an import, staged between wizard steps. `data` holds contact details,
 * so it is encrypted at rest. Rows are deleted when their import completes or is cancelled,
 * and stale rows (24 hours) are purged whenever a new import starts.
 */
class ImportRow extends Model
{
    public const STALE_AFTER_HOURS = 24;

    /**
     * import_id is set through the import's rows() relation, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = ['row_number', 'data', 'errors', 'duplicate_lead_id', 'action'];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'data' => 'encrypted:array',
            'errors' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function duplicateLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_lead_id');
    }

    public static function purgeStale(): int
    {
        return static::query()->where('created_at', '<', now()->subHours(self::STALE_AFTER_HOURS))->delete();
    }
}
