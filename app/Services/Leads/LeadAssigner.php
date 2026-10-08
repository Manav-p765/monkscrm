<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way a lead's assignee changes (form, panel, bulk, import). Writes a system activity and
 * the audit log (lead.assigned). Authorization is the caller's job (LeadPolicy::assign).
 */
class LeadAssigner
{
    public function assign(Lead $lead, ?User $to, ?User $by, string $via): bool
    {
        if ($lead->assigned_to === $to?->id) {
            return false;
        }

        return DB::transaction(function () use ($lead, $to, $by, $via) {
            $from = $lead->assigned_to;
            $lead->assigned_to = $to?->id;
            $lead->last_activity_at = now();
            $lead->save();
            $lead->setRelation('assignee', $to);

            $activity = $lead->activities()->make([
                'type' => ActivityType::System,
                'description' => $to ? "Assigned to {$to->name}" : 'Lead unassigned',
                'occurred_at' => now(),
                'meta' => ['event' => 'assigned', 'from' => $from, 'to' => $to?->id, 'via' => $via],
            ]);
            $activity->user_id = $by?->id;
            $activity->save();

            Audit::log('lead.assigned', $lead, ['from' => $from, 'to' => $to?->id, 'via' => $via], $by?->id);

            return true;
        });
    }
}
