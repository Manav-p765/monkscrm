<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Settings;

/**
 * Lead rules. Visibility itself is Lead::visibleTo() / Lead::isVisibleTo(); a lead the user can't
 * see never even resolves (404). These methods add what a user may DO with leads they can see.
 */
class LeadPolicy
{
    /** Anyone active may open the list; rows are limited by the visibility scope. */
    public function viewAny(User $user): bool
    {
        return Permissions::allows($user, 'view-leads');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $lead->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return Permissions::allows($user, 'edit-leads');
    }

    /** Edit fields and change status on leads you can see. */
    public function update(User $user, Lead $lead): bool
    {
        return Permissions::allows($user, 'edit-leads') && $lead->isVisibleTo($user) && ! $lead->trashed();
    }

    /**
     * Admins and managers assign to anyone; sales may only assign a lead they can edit to themselves.
     * $to = null means "unassigned" (admins and managers only).
     */
    public function assign(User $user, Lead $lead, ?User $to = null): bool
    {
        if (! $this->update($user, $lead)) {
            return false;
        }
        if (Permissions::allows($user, 'assign-leads')) {
            return $to === null || $to->is_active;
        }

        return $to !== null && $to->is($user);
    }

    /** Pick an assignee on the create form (same rule, before the lead exists). */
    public function assignTo(User $user, ?User $to): bool
    {
        if (Permissions::allows($user, 'assign-leads')) {
            return $to === null || $to->is_active;
        }

        return $to !== null && $to->is($user);
    }

    public function archive(User $user, Lead $lead): bool
    {
        return Permissions::allows($user, 'archive-leads') && ! $lead->trashed();
    }

    public function restore(User $user, Lead $lead): bool
    {
        return Permissions::allows($user, 'archive-leads') && $lead->trashed();
    }

    public function viewArchived(User $user): bool
    {
        return Permissions::allows($user, 'archive-leads');
    }

    /** Save despite a possible duplicate: admins/managers, and only while the setting allows it. */
    public function overrideDuplicate(User $user): bool
    {
        return Permissions::allows($user, 'save-duplicates') && (bool) Settings::get('allow_duplicate_override', true);
    }

    public function export(User $user): bool
    {
        return Permissions::allows($user, 'export-leads');
    }
}
