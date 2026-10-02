<?php

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    /**
     * lead_id and user_id are set by the controller, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = ['type', 'description', 'outcome', 'occurred_at', 'meta'];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
