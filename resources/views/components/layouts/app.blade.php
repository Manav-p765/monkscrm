{{--
    Authenticated page shell: one flat sidebar + one main area.
    <x-layouts.app title="Leads" wide>
        <x-slot:actions><x-button :href="...">Add lead</x-button></x-slot:actions>   (max one primary action)
        <x-slot:more><a href="...">Export</a></x-slot:more>                         (optional "More" menu)
        ...
    </x-layouts.app>
    `wide` lets tables use the full width; other pages keep a comfortable reading width.
--}}
@props(['title', 'wide' => false, 'actions' => null, 'more' => null])

@php($user = auth()->user())

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="h-full">
<div x-data="sidebar" class="min-h-full">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

    {{-- Top bar on tablet/phone widths --}}
    <div class="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <span class="font-semibold text-slate-900">{{ config('app.name') }}</span>
        <x-button type="button" variant="secondary" x-on:click="toggle" aria-controls="sidebar" x-bind:aria-expanded="open">Menu</x-button>
    </div>

    <div x-cloak x-show="open" x-on:click="close" class="fixed inset-0 z-30 bg-slate-900/30 lg:hidden"></div>

    <aside id="sidebar"
           x-bind:class="panelClasses"
           x-on:keydown.escape.window="close"
           class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform lg:translate-x-0">
        <div class="px-5 py-5">
            <a href="{{ route('dashboard') }}" class="text-lg font-semibold text-slate-900">{{ config('app.name') }}</a>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3" aria-label="Main">
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
            @can('viewAny', App\Models\Lead::class)
                <x-nav-link :href="route('leads.index')" :active="request()->routeIs('leads.*')">Leads</x-nav-link>
            @endcan
            <x-nav-link :href="route('follow-ups.index')" :active="request()->routeIs('follow-ups.*')">Follow-ups</x-nav-link>
            @can('import-leads')
                <x-nav-link :href="route('imports.index')" :active="request()->routeIs('imports.*')">Imports</x-nav-link>
            @endcan
            <x-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')">Reports</x-nav-link>

            @canany(['view-users', 'manage-settings', 'view-audit-log'])
                <p class="px-3 pt-6 pb-1 text-xs text-slate-500">Administration</p>
            @endcanany
            @can('view-users')
                <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">Users</x-nav-link>
            @endcan
            @can('manage-settings')
                <x-nav-link :href="route('settings.index')" :active="request()->routeIs('settings.*')">Settings</x-nav-link>
            @endcan
            @can('view-audit-log')
                <x-nav-link :href="route('audit-log.index')" :active="request()->routeIs('audit-log.*')">Audit log</x-nav-link>
            @endcan
        </nav>

        <div class="space-y-1 border-t border-slate-200 px-3 py-4">
            <div class="px-3 pb-2">
                <p class="truncate font-medium text-slate-900">{{ $user->name }}</p>
                <p class="text-xs text-slate-500">{{ $user->role->label() }}</p>
            </div>
            <x-nav-link :href="route('profile.show')" :active="request()->routeIs('profile.*')">My profile</x-nav-link>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="block w-full rounded-md px-3 py-2 text-left text-slate-700 hover:bg-slate-100 hover:text-slate-900">
                    Log out
                </button>
            </form>
        </div>
    </aside>

    <div class="lg:pl-60">
        <main id="main" @class(['px-4 py-8 sm:px-6 lg:px-10', 'max-w-4xl' => ! $wide])>
            <x-flash />
            <x-page-header :title="$title" :actions="$actions" :more="$more" />
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
