<?php

namespace App\Actions\Users;

use App\Enums\FollowUpStatus;
use App\Enums\LeadStatusType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a user in one transaction: their open leads and pending follow-ups go to another
 * active user (or become unassigned), and every session they have is deleted so they are signed
 * out everywhere at once. Authorization (UserPolicy::deactivate) is checked by the caller.
 */
class DeactivateUser
{
    /**
     * @return array{leads: int, follow_ups: int}
     */
    public function __invoke(User $user, ?User $reassignTo): array
    {
        return DB::transaction(function () use ($user, $reassignTo) {
            $user->forceFill(['is_active' => false])->save();

            // Admin-level maintenance across all of this user's leads, not a user-facing list,
            // so the per-user visibility scope does not apply here.
            $leads = Lead::query()
                ->where('assigned_to', $user->id)
                ->whereHas('status', fn ($q) => $q->where('type', LeadStatusType::Open))
                ->update(['assigned_to' => $reassignTo?->id]);

            $followUps = FollowUp::query()
                ->where('assigned_to', $user->id)
                ->where('status', FollowUpStatus::Pending)
                ->update(['assigned_to' => $reassignTo?->id]);

            DB::table('sessions')->where('user_id', $user->id)->delete();

            Audit::log('user.deactivated', $user, [
                'reassigned_to' => $reassignTo?->id,
                'leads_reassigned' => $leads,
                'follow_ups_reassigned' => $followUps,
            ]);

            return ['leads' => $leads, 'follow_ups' => $followUps];
        });
    }
}
