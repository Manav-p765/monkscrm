<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way a lead's status changes (form, panel, bulk, kanban, import). It:
 * - stamps the status's milestone timestamp the first time only (never overwritten),
 * - writes a system activity ("Status changed from X to Y"),
 * - writes the audit log (lead.status_changed).
 * Authorization is the caller's job (LeadPolicy::update).
 */
class LeadStatusChanger
{
    /**
     * @param  string  $via  where it happened: form, panel, bulk, kanban, import
     * @return bool  whether anything changed
     */
    public function change(Lead $lead, LeadStatus $to, ?User $by, string $via): bool
    {
        if ($lead->lead_status_id === $to->id) {
            return false;
        }

        return DB::transaction(function () use ($lead, $to, $by, $via) {
            $from = $lead->status;

            $lead->lead_status_id = $to->id;
            $this->stampMilestone($lead, $to);
            $lead->last_activity_at = now();
            $lead->save();
            $lead->setRelation('status', $to);

            $activity = $lead->activities()->make([
                'type' => ActivityType::System,
                'description' => $from
                    ? "Status changed from \"{$from->name}\" to \"{$to->name}\""
                    : "Status set to \"{$to->name}\"",
                'occurred_at' => now(),
                'meta' => ['event' => 'status_changed', 'from' => $from?->id, 'to' => $to->id, 'via' => $via],
            ]);
            $activity->user_id = $by?->id;
            $activity->save();

            Audit::log('lead.status_changed', $lead, [
                'from' => $from?->name, 'to' => $to->name, 'via' => $via,
            ], $by?->id);

            return true;
        });
    }

    /** For a lead being created: stamp the initial status's milestone (no activity/audit here). */
    public function stampMilestone(Lead $lead, LeadStatus $status): void
    {
        $column = $status->milestone?->column();
        if ($column && $lead->{$column} === null) {
            $lead->{$column} = now();
        }
    }
}
