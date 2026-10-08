<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadNote extends Model
{
    use SoftDeletes;

    /** The author may edit their note for this many minutes after writing it. */
    public const EDIT_WINDOW_MINUTES = 15;

    /**
     * lead_id and user_id are set by the controller, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = ['body'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 404 unless the note's lead is visible to the signed-in user. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $user = auth()->user();

        return $user ? static::query()->where($field ?? 'id', $value)
            ->whereHas('lead', fn ($q) => $q->visibleTo($user))->first() : null;
    }

    public function isEditableBy(User $user): bool
    {
        return $this->user_id === $user->id && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }
}
