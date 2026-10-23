<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\Currency;
use App\Enums\SubmissionResult;
use App\Models\Integration;
use App\Models\IntegrationSubmission;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\Imports\LeadImporter;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Leads from outside (website forms today, ad platforms later): map whatever field names a form
 * sends onto lead fields, then create the lead, or, when the email/phone is already known, add the
 * submission to that lead's timeline instead of creating a duplicate.
 *
 * Only contact fields can come from outside. Status, source and assignee come from the integration,
 * never from the payload. Anything we can't map is kept on the timeline, so nothing the visitor
 * typed is lost.
 */
class LeadIntake
{
    /** Lead fields a form may fill, with their column lengths (longer values are cut, not rejected). */
    public const FIELDS = [
        'first_name' => 100, 'last_name' => 100, 'company' => 191, 'job_title' => 150,
        'email' => 191, 'phone' => 50, 'website' => 255, 'address_line1' => 191, 'city' => 100,
        'state' => 100, 'postal_code' => 20, 'country' => 100, 'description' => 5000,
    ];

    /** Ad / analytics parameters, kept on the activity (lowercased key => label). */
    public const TRACKING = [
        'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign',
        'utm_term' => 'UTM term', 'utm_content' => 'UTM content', 'gclid' => 'Google click ID',
        'fbclid' => 'Facebook click ID', 'msclkid' => 'Microsoft click ID', 'li_fat_id' => 'LinkedIn click ID',
        'page_url' => 'Page', 'landing_page' => 'Landing page', 'referrer' => 'Referrer',
    ];

    /** The honeypot field: hidden on the form, so only bots fill it. */
    public const HONEYPOT = '_hp';

    private const MAX_FIELDS = 50;

    private const MAX_VALUE = 2000;

    public function __construct(
        private LeadImporter $importer,
        private LeadDuplicates $duplicates,
        private LeadAssigner $assigner,
        private LeadStatusChanger $statuses,
    ) {}

    /**
     * Raw payload → lead fields, extras (unmapped "Label" => value), tracking, the field names received
     * and whether the honeypot was filled.
     *
     * @return array{fields: array<string, string>, extras: array<string, string>, tracking: array<string, string>, names: list<string>, spam: bool}
     */
    public function map(array $payload): array
    {
        $spam = trim((string) ($payload[self::HONEYPOT] ?? '')) !== '';
        $values = $tracking = [];

        foreach ($payload as $key => $value) {
            $key = mb_substr(trim((string) $key), 0, 64);
            $value = $this->scalar($value);
            if ($key === '' || $value === null || $this->ignored($key)) {
                continue;
            }
            if (isset(self::TRACKING[mb_strtolower($key)])) {
                $tracking[mb_strtolower($key)] = mb_substr($value, 0, 500);

                continue;
            }
            if (count($values) < self::MAX_FIELDS) {
                $values[$key] = mb_substr($value, 0, self::MAX_VALUE);
            }
        }

        // Guess on the name without form-builder prefixes ("your-email" → "email").
        $guessable = [];
        foreach (array_keys($values) as $key) {
            $guessable[$key] = preg_replace('/^(your|form|field|input)[-_ ]+/i', '', $key);
        }
        $mapping = array_combine(array_keys($guessable), $this->importer->guessMapping(array_values($guessable)));

        $fields = $extras = [];
        foreach ($values as $key => $value) {
            $field = $mapping[$key] ?? 'none';
            if ($value === '') {
                continue;
            }
            if ($field === 'full_name') {
                $parts = preg_split('/\s+/', $value, 2);
                $fields['first_name'] ??= $parts[0];
                $fields['last_name'] ??= $parts[1] ?? null;
            } elseif (isset(self::FIELDS[$field]) && ! isset($fields[$field])) {
                $fields[$field] = $value;
            } else {
                $extras[Str::headline($guessable[$key]) ?: $key] = $value;
            }
        }

        // A mistyped email shouldn't lose a lead that also left a phone number.
        if (isset($fields['email'])) {
            $fields['email'] = mb_strtolower($fields['email']);
            if (! filter_var($fields['email'], FILTER_VALIDATE_EMAIL) && isset($fields['phone'])) {
                $extras['Email (not valid)'] = $fields['email'];
                unset($fields['email']);
            }
        }
        if (isset($fields['website']) && ! preg_match('#^https?://#i', $fields['website'])) {
            $fields['website'] = 'https://'.$fields['website'];
        }
        foreach ($fields as $field => $value) {
            $fields[$field] = mb_substr((string) $value, 0, self::FIELDS[$field]);
        }

        return [
            'fields' => array_filter($fields, fn ($v) => $v !== ''),
            'extras' => $extras,
            'tracking' => $tracking,
            'names' => array_slice(array_map('strval', array_keys($payload)), 0, self::MAX_FIELDS),
            'spam' => $spam,
        ];
    }

    /**
     * Create the lead, or log the submission on the lead that already has this email/phone.
     *
     * @param  array<string, string>  $fields  validated lead fields
     * @return array{0: SubmissionResult, 1: Lead}
     */
    public function capture(Integration $integration, array $fields, array $extras, array $tracking): array
    {
        return DB::transaction(function () use ($integration, $fields, $extras, $tracking) {
            $details = $this->details($extras, $tracking);
            $existing = $this->duplicates->find($fields['email'] ?? null, $fields['phone'] ?? null)->first();

            if ($existing) {
                $this->activity($existing, $integration, "Sent the form “{$integration->name}” again", $details, $tracking, $fields);
                $existing->last_activity_at = now();
                $existing->save();
                Audit::log('lead.recaptured', $existing, ['integration_id' => $integration->id, 'reference' => $existing->reference]);

                return [SubmissionResult::Duplicate, $existing];
            }

            $lead = new Lead(collect($fields)->only(array_keys(self::FIELDS))->all());
            $lead->lead_status_id = (int) Settings::get('default_status_id');
            $lead->lead_source_id = $integration->lead_source_id ?? ((int) Settings::get('default_source_id') ?: null);
            $lead->currency = Currency::default();
            $lead->created_by = $integration->created_by;
            $lead->integration_id = $integration->id;
            $lead->last_activity_at = now();
            $this->statuses->stampMilestone($lead, LeadStatus::findOrFail($lead->lead_status_id));
            $lead->save();

            $this->activity($lead, $integration, "Received from the form “{$integration->name}”", $details, $tracking);
            Audit::log('lead.captured', $lead, ['integration_id' => $integration->id, 'reference' => $lead->reference]);

            $assignee = $integration->assignee;
            if ($assignee?->is_active) {
                $this->assigner->assign($lead, $assignee, null, 'integration');
            }

            return [SubmissionResult::Created, $lead];
        });
    }

    /** Record what a post did (field names and errors only) and prune old entries. */
    public function log(Integration $integration, SubmissionResult $result, ?Lead $lead, array $names, array $errors = [], ?string $ip = null): void
    {
        $submission = $integration->submissions()->make([
            'result' => $result, 'fields' => $names ?: null, 'errors' => $errors ?: null, 'ip_address' => $ip,
        ]);
        $submission->lead_id = $lead?->id;
        $submission->created_at = now();
        $submission->save();

        $integration->forceFill(['last_received_at' => now()])->save();

        IntegrationSubmission::query()->where('created_at', '<', now()->subDays(Integration::KEEP_SUBMISSIONS_DAYS))->delete();
    }

    /** The form's other answers, then the ad tracking, as "Label: value" lines. */
    private function details(array $extras, array $tracking): string
    {
        $lines = [];
        foreach ($extras as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }
        foreach ($tracking as $key => $value) {
            $lines[] = self::TRACKING[$key].": {$value}";
        }

        return implode("\n", $lines);
    }

    private function activity(Lead $lead, Integration $integration, string $title, string $details, array $tracking, array $fields = []): void
    {
        // On a repeat, the message they typed this time belongs on the timeline too.
        if (! empty($fields['description'])) {
            $details = trim("Message: {$fields['description']}\n{$details}");
        }

        $activity = $lead->activities()->make([
            'type' => ActivityType::System,
            'description' => trim($title."\n".$details),
            'occurred_at' => now(),
            'meta' => ['event' => 'captured', 'integration_id' => $integration->id, 'tracking' => $tracking ?: null],
        ]);
        $activity->user_id = null;
        $activity->save();
    }

    private function scalar(mixed $value): ?string
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value']; // Elementor's "advanced data" shape
        }
        if (is_array($value)) {
            $items = array_filter($value, fn ($v) => is_scalar($v));

            return $items === [] ? null : trim(implode(', ', array_map('strval', $items)));
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    private function ignored(string $key): bool
    {
        return str_starts_with($key, '_')
            || in_array(mb_strtolower($key), ['g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'submit', 'action'], true);
    }
}
