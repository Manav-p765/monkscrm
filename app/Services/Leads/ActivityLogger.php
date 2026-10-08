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
 * first_contacted_at (the "contacted" milestone).
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

        $this->touch($lead, $at, in_array($type, self::CONTACT_TYPES, true));

        return $activity;
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
