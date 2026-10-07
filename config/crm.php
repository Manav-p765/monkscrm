<?php

return [

    /*
    | Everything is stored in UTC (config('app.timezone')). Dates are shown in, and dates users
    | type are read as, this timezone. No settings UI: our team works in India.
    */
    'display_timezone' => 'Asia/Kolkata',

    /*
    | Who may do what: the single source for the gates (AppServiceProvider defines one gate per
    | `gate` entry), the policies (which read the role lists below) and the read-only permissions
    | table on the Settings page. Roles are App\Enums\Role values. `setting` marks a sales permission
    | that an admin setting can widen (shown as "Configurable" in the table).
    */
    'permissions' => [
        ['key' => 'view-leads', 'label' => 'View assigned leads', 'roles' => ['admin', 'manager', 'sales']],
        ['key' => 'view-unassigned-leads', 'label' => 'View unassigned leads', 'roles' => ['admin', 'manager'], 'setting' => 'sales_can_view_unassigned'],
        ['key' => 'view-all-leads', 'label' => 'View all leads', 'roles' => ['admin', 'manager'], 'setting' => 'sales_can_view_all_leads'],
        ['key' => 'edit-leads', 'label' => 'Create and edit leads', 'roles' => ['admin', 'manager', 'sales']],
        ['key' => 'log-activity', 'label' => 'Add notes and log activities', 'roles' => ['admin', 'manager', 'sales']],
        ['key' => 'schedule-follow-ups', 'label' => 'Schedule follow-ups', 'roles' => ['admin', 'manager', 'sales']],
        ['key' => 'assign-leads', 'label' => 'Assign leads to others', 'roles' => ['admin', 'manager']],
        ['key' => 'save-duplicates', 'label' => 'Save possible duplicates', 'roles' => ['admin', 'manager'], 'setting' => 'allow_duplicate_override'],
        ['key' => 'import-leads', 'label' => 'Import leads (CSV, Excel)', 'roles' => ['admin', 'manager'], 'gate' => true],
        ['key' => 'export-leads', 'label' => 'Export leads', 'roles' => ['admin', 'manager'], 'gate' => true],
        ['key' => 'view-reports', 'label' => 'View reports', 'roles' => ['admin', 'manager'], 'gate' => true],
        ['key' => 'archive-leads', 'label' => 'Archive leads', 'roles' => ['admin']],
        ['key' => 'view-users', 'label' => 'View users and teams', 'roles' => ['admin', 'manager'], 'gate' => true],
        ['key' => 'manage-users', 'label' => 'Invite and manage users', 'roles' => ['admin'], 'gate' => true],
        ['key' => 'manage-teams', 'label' => 'Add and rename teams', 'roles' => ['admin'], 'gate' => true],
        ['key' => 'manage-settings', 'label' => 'Configure statuses, sources, tags and settings', 'roles' => ['admin'], 'gate' => true],
        ['key' => 'view-audit-log', 'label' => 'View the audit log', 'roles' => ['admin'], 'gate' => true],
    ],

    /*
    | Colours an admin can pick for statuses and tags: exactly the prototype's palette
    | (design/src/utils/crmHelpers.ts getStatusBadgeClasses). The hex value is what we store;
    | <x-badge> maps it back to the prototype's Tailwind classes.
    */
    'palette' => [
        'blue' => ['label' => 'Blue', 'hex' => '#3b82f6', 'badge' => 'bg-blue-50 text-blue-700 border-blue-200', 'dot' => 'bg-blue-500'],
        'indigo' => ['label' => 'Indigo', 'hex' => '#6366f1', 'badge' => 'bg-indigo-50 text-indigo-700 border-indigo-200', 'dot' => 'bg-indigo-500'],
        'purple' => ['label' => 'Purple', 'hex' => '#a855f7', 'badge' => 'bg-purple-50 text-purple-700 border-purple-200', 'dot' => 'bg-purple-500'],
        'amber' => ['label' => 'Amber', 'hex' => '#f59e0b', 'badge' => 'bg-amber-50 text-amber-700 border-amber-200', 'dot' => 'bg-amber-500'],
        'emerald' => ['label' => 'Emerald', 'hex' => '#10b981', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500'],
        'rose' => ['label' => 'Rose', 'hex' => '#f43f5e', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200', 'dot' => 'bg-rose-500'],
        'teal' => ['label' => 'Teal', 'hex' => '#14b8a6', 'badge' => 'bg-teal-50 text-teal-700 border-teal-200', 'dot' => 'bg-teal-500'],
        'slate' => ['label' => 'Slate', 'hex' => '#64748b', 'badge' => 'bg-slate-100 text-slate-700 border-slate-200', 'dot' => 'bg-slate-400'],
    ],

];
