<?php

namespace App\Models;

use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A connection that sends leads in from outside (POST /intake/{public_key}). Never deleted, only
 * turned off, so the submissions log and leads.integration_id keep pointing at it.
 *
 * Two ways in: server-to-server posts carry the secret (Bearer header or an HMAC signature); browser
 * posts are allowed only when allow_browser is on and the Origin is one of allowed_origins.
 */
class Integration extends Model
{
    /** How old a signed timestamp may be (seconds), against replays. */
    public const SIGNATURE_TOLERANCE = 300;

    /** Submissions older than this are pruned whenever a new one is logged (no scheduler). */
    public const KEEP_SUBMISSIONS_DAYS = 90;

    /**
     * type, public_key, secret and created_by are set in code.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'lead_source_id', 'assign_to', 'allow_browser', 'allowed_origins', 'redirect_url', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'type' => IntegrationType::class,
            'secret' => 'encrypted',
            'allow_browser' => 'boolean',
            'allowed_origins' => 'array',
            'is_active' => 'boolean',
            'last_received_at' => 'datetime',
        ];
    }

    public static function generateKey(): string
    {
        return 'in_'.Str::lower(Str::random(32));
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Server-to-server: "Authorization: Bearer <secret>", or X-CRM-Timestamp + X-CRM-Signature. */
    public function acceptsSecret(?string $bearer, ?string $timestamp, ?string $signature, string $body): bool
    {
        if ($bearer !== null && $bearer !== '') {
            return hash_equals($this->secret, $bearer);
        }
        if (! $timestamp || ! $signature || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        return hash_equals($expected, $signature);
    }

    /** Browser post from one of the allowed sites ("https://example.com", host and port must match). */
    public function acceptsOrigin(?string $origin): bool
    {
        $origin = self::normalizeOrigin($origin);

        return $this->allow_browser && $origin !== null && in_array($origin, $this->allowed_origins ?? [], true);
    }

    /** "https://Example.com/contact" → "https://example.com"; null when it isn't a web address. */
    public static function normalizeOrigin(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value !== '' && ! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || (! str_contains($host, '.') && $host !== 'localhost')) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function intakeUrl(): string
    {
        return route('intake.store', $this->public_key);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(IntegrationSubmission::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
