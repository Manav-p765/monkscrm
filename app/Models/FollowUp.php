<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    /**
     * lead_id and created_by are set by the controller, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = ['assigned_to', 'type', 'due_at', 'notes', 'status', 'completed_at'];

    protected function casts(): array
    {
        return [
            'type' => FollowUpType::class,
            'status' => FollowUpStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Overdue is computed, never stored: pending AND due_at in the past.
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', FollowUpStatus::Pending)->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === FollowUpStatus::Pending && $this->due_at->isPast();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
