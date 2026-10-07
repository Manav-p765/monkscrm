<?php

namespace App\View;

/**
 * Prototype form-control classes shared by <x-input>, <x-select> and <x-textarea>.
 * md: the app's forms and modals (LeadFormModal). lg: the sign-in screens (AuthView).
 */
class FormStyles
{
    public static function control(string $size = 'md', bool $error = false, bool $icon = false, bool $trailing = false): string
    {
        $base = $size === 'lg'
            ? 'block w-full bg-white border rounded-xl py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-1'
            : 'block w-full bg-slate-50 border rounded-lg py-2 text-xs text-slate-900 focus:bg-white focus:outline-none focus:ring-2';

        $state = match (true) {
            $error && $size === 'lg' => 'border-rose-400 focus:border-rose-600 focus:ring-rose-600',
            $error => 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/20',
            $size === 'lg' => 'border-slate-300 focus:border-indigo-600 focus:ring-indigo-600',
            default => 'border-slate-200 focus:border-indigo-500 focus:ring-indigo-500/20',
        };

        $padding = ($icon ? ($size === 'lg' ? 'pl-10' : 'pl-9') : 'pl-3').' '.($trailing ? 'pr-10' : 'pr-3');

        return "$base $state $padding";
    }
}
