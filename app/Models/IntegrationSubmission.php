<?php

namespace App\Models;

use App\Enums\SubmissionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post to an integration and what it did. Stores the field NAMES received (to debug a form's
 * mapping) and plain-language errors, never the submitted values: those live only on the lead.
 */
class IntegrationSubmission extends Model
{
    public const UPDATED_AT = null;

    /**
     * integration_id and lead_id are set in code.
     *
     * @var list<string>
     */
    protected $fillable = ['result', 'fields', 'errors', 'ip_address'];

    protected function casts(): array
    {
        return [
            'result' => SubmissionResult::class,
            'fields' => 'array',
            'errors' => 'array',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
