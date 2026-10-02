<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Models\Concerns\Searchable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * NOTE: never query leads directly (e.g. Lead::all()). All lead queries must go
 * through the visibility scope added in step 3.
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, Searchable, SoftDeletes;

    /**
     * Ownership/system fields (reference, created_by, original_source_id, *_normalized,
     * activity timestamps) are deliberately not fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'company',
        'job_title',
        'email',
        'secondary_email',
        'phone',
        'secondary_phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'website',
        'description',
        'lead_status_id',
        'lead_source_id',
        'priority',
        'assigned_to',
        'next_follow_up_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => LeadPriority::class,
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            $lead->email_normalized = static::normalizeEmail($lead->email);
            $lead->phone_normalized = static::normalizePhone($lead->phone);
        });

        static::creating(function (Lead $lead) {
            $lead->original_source_id ??= $lead->lead_source_id;
        });

        static::created(function (Lead $lead) {
            $lead->reference = static::formatReference($lead->id);
            $lead->saveQuietly();
        });

        static::updating(function (Lead $lead) {
            if ($lead->isDirty('original_source_id') || $lead->isDirty('reference')) {
                throw new LogicException('original_source_id and reference cannot change after creation.');
            }
        });
    }

    public static function formatReference(int $id): string
    {
        return 'LD-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function originalSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'original_source_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }
}
