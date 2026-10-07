<?php

return [

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
