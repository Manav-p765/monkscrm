<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\Dates;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up lifecycle: schedule, complete (optional outcome → logged activity), cancel, reschedule,
 * reopen. After every change, leads.next_follow_up_at is re-synced to the earliest pending follow-up.
 * "Overdue" is never stored: pending AND due_at < now(), computed at query time.
 */
class FollowUps
{
    public function __construct(private ActivityLogger $activities) {}

    public function schedule(Lead $lead, User $by, User $assignee, FollowUpType $type, CarbonInterface $due, ?string $notes): FollowUp
    {
        return DB::transaction(function () use ($lead, $by, $assignee, $type, $due, $notes) {
            $followUp = $lead->followUps()->make([
                'assigned_to' => $assignee->id,
                'type' => $type,
                'due_at' => $due,
                'notes' => $notes,
                'status' => FollowUpStatus::Pending,
            ]);
            $followUp->created_by = $by->id;
            $followUp->save();

            $this->activities->system($lead, $by, 'follow_up_scheduled',
                "Scheduled {$type->label()} follow-up for ".Dates::format($due)." with {$assignee->name}", ['follow_up_id' => $followUp->id]);
            $this->activities->touch($lead, now(), false);
            $this->sync($lead);

            return $followUp;
        });
    }

    public function complete(FollowUp $followUp, User $by, ?string $outcome): void
    {
        DB::transaction(function () use ($followUp, $by, $outcome) {
            $followUp->update(['status' => FollowUpStatus::Completed, 'completed_at' => now(), 'outcome' => $outcome]);
            $lead = $followUp->lead;

            if ($outcome !== null && $outcome !== '') {
                // An outcome means something happened: log it like an activity.
                $type = in_array($followUp->type, [FollowUpType::Call, FollowUpType::Email, FollowUpType::Meeting], true)
                    ? ActivityType::from($followUp->type->value) : ActivityType::FollowUp;
                $this->activities->log($lead, $by, $type, "Follow-up ({$followUp->type->label()}) completed", $outcome, now());
            } else {
                $this->activities->system($lead, $by, 'follow_up_completed', "Follow-up ({$followUp->type->label()}) marked as done", ['follow_up_id' => $followUp->id]);
                $this->activities->touch($lead, now(), false);
            }
            $this->sync($lead);
        });
    }

    public function cancel(FollowUp $followUp, User $by): void
    {
        DB::transaction(function () use ($followUp, $by) {
            $followUp->update(['status' => FollowUpStatus::Cancelled, 'completed_at' => null]);
            $this->activities->system($followUp->lead, $by, 'follow_up_cancelled', "Follow-up ({$followUp->type->label()}) cancelled", ['follow_up_id' => $followUp->id]);
            $this->sync($followUp->lead);
        });
    }

    public function reschedule(FollowUp $followUp, User $by, CarbonInterface $due): void
    {
        DB::transaction(function () use ($followUp, $by, $due) {
            $followUp->update(['due_at' => $due]);
            $this->activities->system($followUp->lead, $by, 'follow_up_rescheduled', "Follow-up ({$followUp->type->label()}) moved to ".Dates::format($due), ['follow_up_id' => $followUp->id]);
            $this->sync($followUp->lead);
        });
    }

    public function reopen(FollowUp $followUp, User $by): void
    {
        DB::transaction(function () use ($followUp, $by) {
            $followUp->update(['status' => FollowUpStatus::Pending, 'completed_at' => null, 'outcome' => null]);
            $this->activities->system($followUp->lead, $by, 'follow_up_reopened', "Follow-up ({$followUp->type->label()}) reopened", ['follow_up_id' => $followUp->id]);
            $this->sync($followUp->lead);
        });
    }

    /** leads.next_follow_up_at = the earliest pending follow-up (or null). */
    public function sync(Lead $lead): void
    {
        $next = $lead->followUps()->where('status', FollowUpStatus::Pending)->min('due_at');
        $lead->next_follow_up_at = $next;
        if ($lead->isDirty('next_follow_up_at')) {
            $lead->save();
        }
    }
}
