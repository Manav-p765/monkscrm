<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only. Rows may be created but never updated or deleted.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Written only by the audit helper (step 2), never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit log entries are append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log entries are append-only.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /** "Signed in", "Changed a user’s role", … (config/crm.php audit_actions). */
    public function actionLabel(): string
    {
        return config("crm.audit_actions.{$this->action}", $this->action);
    }

    /** What the action was about, for people: a user's name, a lead reference, a status name. */
    public function subjectLabel(): ?string
    {
        if (! $this->subject_type) {
            return null;
        }

        $subject = $this->subject;
        $type = class_basename($this->subject_type);

        return match (true) {
            $subject instanceof Lead => $subject->reference,
            $subject !== null && isset($subject->name) => $subject->name,
            default => "{$type} #{$this->subject_id}",
        };
    }

    public function subjectType(): ?string
    {
        return $this->subject_type ? \Illuminate\Support\Str::headline(class_basename($this->subject_type)) : null;
    }
}
