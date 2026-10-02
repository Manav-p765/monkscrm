<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * STUB (step 1). Full rules are implemented in step 3, together with the lead
 * visibility scope. Until then everything except viewAny is denied.
 */
class LeadPolicy
{
    /**
     * TODO(step 3): any active user may open the leads list; rows are limited by the visibility scope.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * TODO(step 3): admin/manager: any lead. Sales: leads assigned to or created by them,
     * or all leads when the sales_can_view_all_leads setting is on.
     */
    public function view(User $user, Lead $lead): bool
    {
        return false;
    }

    /**
     * TODO(step 3): all roles may create leads.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * TODO(step 3): admin/manager: any lead. Sales: only leads they can view and are assigned to.
     */
    public function update(User $user, Lead $lead): bool
    {
        return false;
    }

    /**
     * TODO(step 3): archive (soft delete). Admin/manager only.
     */
    public function delete(User $user, Lead $lead): bool
    {
        return false;
    }

    /**
     * TODO(step 3): change assigned_to. Admin/manager only.
     */
    public function assign(User $user, Lead $lead): bool
    {
        return false;
    }

    /**
     * TODO(step 3/6): mirrors the export-leads gate (admin, manager).
     */
    public function export(User $user): bool
    {
        return false;
    }
}
