<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Logs what happened on a lead (call, email, meeting, sms, other) and keeps the lead's dates right:
 * last_activity_at always; calls/emails/meetings/SMS also set last_contacted_at and, the first time,
 * first_contacted_at (the "contacted" milestone), unless the outcome says the person wasn't reached.
 */
class ActivityLogger
{
    public const CONTACT_TYPES = [ActivityType::Call, ActivityType::Email, ActivityType::Meeting, ActivityType::Sms];

    public const LOGGABLE = [ActivityType::Call, ActivityType::Email, ActivityType::Meeting, ActivityType::Sms, ActivityType::Other];

    public function log(Lead $lead, User $by, ActivityType $type, string $description, ?string $outcome, CarbonInterface $at): Activity
    {
        $activity = $lead->activities()->make([
            'type' => $type,
            'description' => $description,
            'outcome' => $outcome,
            'occurred_at' => $at,
            'meta' => ['event' => 'logged'],
        ]);
        $activity->user_id = $by->id;
        $activity->save();

        $this->touch($lead, $at, in_array($type, self::CONTACT_TYPES, true) && ! self::isNoAnswer($outcome));

        return $activity;
    }

    /**
     * Outcomes meaning the person wasn't reached ("No answer", "Left voicemail", "Busy", …). Such calls,
     * emails, meetings or SMS stay on the timeline but don't count as contact (first/last contacted).
     * The outcome is free text, so this matches common phrasings, case- and punctuation-insensitive.
     */
    public const NO_ANSWER_PHRASES = [
        'no answer', 'not answered', 'didnt answer', 'did not answer', 'unanswered',
        'no response', 'no reply', 'didnt reply', 'did not reply',
        'voicemail', 'voice mail', 'busy', 'unreachable', 'not reachable',
        'switched off', 'not picked', 'didnt pick', 'did not pick', 'no show', 'bounced',
    ];

    public static function isNoAnswer(?string $outcome): bool
    {
        // "Didn't" → "didnt"; every other non-letter separates words ("no-answer" → "no answer").
        $text = str_replace(['’', "'"], '', mb_strtolower((string) $outcome));
        $text = trim(preg_replace('/[^a-z]+/', ' ', $text));

        if ($text === '') {
            return false;
        }

        foreach (self::NO_ANSWER_PHRASES as $phrase) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/', $text)) {
                return true;
            }
        }

        return false;
    }

    /** A system entry on the timeline (follow-up scheduled/completed, note deleted, …). */
    public function system(Lead $lead, ?User $by, string $event, string $description, array $meta = []): Activity
    {
        $activity = $lead->activities()->make([
            'type' => ActivityType::System,
            'description' => $description,
            'occurred_at' => now(),
            'meta' => ['event' => $event, ...$meta],
        ]);
        $activity->user_id = $by?->id;
        $activity->save();

        return $activity;
    }

    public function touch(Lead $lead, CarbonInterface $at, bool $contact): void
    {
        if ($lead->last_activity_at === null || $at->gt($lead->last_activity_at)) {
            $lead->last_activity_at = $at;
        }
        if ($contact) {
            if ($lead->last_contacted_at === null || $at->gt($lead->last_contacted_at)) {
                $lead->last_contacted_at = $at;
            }
            $lead->first_contacted_at ??= $at;
        }
        $lead->save();
    }
}
