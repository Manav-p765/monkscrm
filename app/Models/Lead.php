<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Models\Concerns\Searchable;
use App\Support\Settings;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * NOTE: never query leads directly (e.g. Lead::all()). Every lead query a person can trigger
 * goes through Lead::visibleTo($user) (see scopeVisibleTo).
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
        'deal_value',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'priority' => LeadPriority::class,
            'deal_value' => 'integer',
            'currency' => Currency::class,
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_activity_at' => 'datetime',
            // Milestone stamps: set once by the status-change logic (step 3), never mass-assigned.
            'first_contacted_at' => 'datetime',
            'qualified_at' => 'datetime',
            'meeting_booked_at' => 'datetime',
            'proposal_sent_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
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
            $lead->currency ??= Currency::default();
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

    /**
     * THE lead visibility rule (CLAUDE.md rule 2). Every lead query a person can trigger goes through
     * this: lists, search, counts, dashboard, kanban, reports, duplicate checks, exports, dropdowns.
     * Admin and manager: all leads (teams don't limit visibility). Sales: leads assigned to them, plus
     * unassigned leads if `sales_can_view_unassigned`, plus everything if `sales_can_view_all_leads`.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->isAdmin() || $user->isManager() || Settings::get('sales_can_view_all_leads', false)) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to', $user->id);
            if (Settings::get('sales_can_view_unassigned', true)) {
                $q->orWhereNull('assigned_to');
            }
        });
    }

    /** Same rule as scopeVisibleTo() for an already-loaded lead. */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($user->isAdmin() || $user->isManager() || Settings::get('sales_can_view_all_leads', false)) {
            return true;
        }

        return $this->assigned_to === $user->id
            || ($this->assigned_to === null && Settings::get('sales_can_view_unassigned', true));
    }

    /** URLs use the public reference (/leads/LD-000123). */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * A lead outside the signed-in user's visibility is a 404 (never a 403), so its existence isn't
     * revealed. Archived leads resolve only on routes that ask for them (->withTrashed()).
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return static::query()->visibleTo($user)->where($field ?? 'reference', $value)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return static::withTrashed()->visibleTo($user)->where($field ?? 'reference', $value)->first();
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

    /** What to call the lead in lists and messages: the person, else the company, else the reference. */
    public function displayName(): string
    {
        return $this->fullName() ?: ($this->company ?: (string) $this->reference);
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
