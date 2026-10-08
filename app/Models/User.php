<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Searchable;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, Searchable, TwoFactorAuthenticatable;

    /**
     * role and is_active are mass-assignable for admin user management (step 2),
     * which must always go through the manage-users gate and a Form Request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'team_id',
        'job_title',
        'phone',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'sales',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Emails are always stored lowercased and trimmed (uniqueness is case-insensitive).
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    /** "Priya Patel" → "PP"; shown in avatar circles (no photos). */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    public function isSales(): bool
    {
        return $this->role === Role::Sales;
    }

    public function hasAnyRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Send the reset email after the HTTP response, so the forgot-password response
     * time doesn't differ between existing and unknown email addresses.
     */
    /**
     * A new single-use set-password link (48 hours). Replaces any earlier invite link.
     * The raw token only exists in this URL; the database stores a hash.
     */
    public function createInviteLink(): string
    {
        $token = Password::broker('invites')->createToken($this);

        return route('invite.show', ['token' => $token, 'email' => $this->email]);
    }

    /** Invited but never signed in: the invite can be resent. */
    public function isInvitePending(): bool
    {
        return $this->last_login_at === null;
    }

    /** Active admins other than this user (there must always be at least one active admin). */
    public static function otherActiveAdminsExist(self $except): bool
    {
        return static::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->whereKeyNot($except->getKey())
            ->exists();
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $notification = new ResetPassword($token);

        defer(fn () => $this->notify($notification));
    }

    /** Display and filtering only; teams don't change lead visibility. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function createdLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'created_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_to');
    }
}
