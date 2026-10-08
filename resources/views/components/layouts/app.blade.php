{{--
    Authenticated page shell, matching the prototype (Sidebar.tsx + Navbar.tsx + a view's header).

    <x-layouts.app title="Lead Directory" subtitle="Search, filter, assign…" :count="$leads->total().' leads'" width="7xl">
        <x-slot:actions><x-button :href="…" icon="plus">Add Lead</x-button></x-slot:actions>
        <x-slot:more><a href="…">Export CSV</a></x-slot:more>
        …
    </x-layouts.app>

    width: 5xl (settings) | 6xl (dashboard) | 7xl (lists, default).
    `header` slot replaces the standard page header (e.g. the dashboard greeting).
--}}
@props(['title', 'subtitle' => null, 'count' => null, 'width' => '7xl', 'actions' => null, 'more' => null, 'header' => null])

@php
    $user = auth()->user();
    $maxWidth = ['5xl' => 'max-w-5xl', '6xl' => 'max-w-6xl', '7xl' => 'max-w-7xl', 'full' => 'max-w-none'][$width] ?? 'max-w-7xl';
    $canCreateLead = $user->can('create', App\Models\Lead::class);
    $alerts = \App\Support\Alerts::for($user);
    $visibleLeadCount = $user->can('viewAny', App\Models\Lead::class) ? App\Models\Lead::query()->visibleTo($user)->count() : 0;
    $dueCount = $alerts['counts']['overdue'] + $alerts['counts']['today'];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="h-full bg-slate-50 font-sans text-slate-900">
<div x-data="sidebar" class="min-h-full">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

    <div x-cloak x-show="open" x-on:click="close" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside id="sidebar" x-bind:class="panelClasses" x-on:keydown.escape.window="close"
           class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform select-none lg:translate-x-0">
        <div class="flex h-16 items-center justify-between border-b border-slate-100 px-5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-600 text-xs font-bold tracking-wider text-white shadow-xs" aria-hidden="true">CRM</span>
                <span>
                    <span class="block text-sm leading-tight font-bold text-slate-900">{{ config('app.name') }}</span>
                    <span class="block text-2xs text-slate-500">Internal Management</span>
                </span>
            </a>
        </div>

        @if ($canCreateLead)
            <div class="p-3">
                <x-button :href="route('leads.create')" icon="plus" class="w-full shadow-2xs">New Lead</x-button>
            </div>
        @endif

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-1" aria-label="Main">
            <p class="px-2.5 py-1.5 text-3xs font-bold tracking-wider text-slate-500 uppercase">Workspace</p>
            <x-nav-link icon="layout-dashboard" :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
            @can('viewAny', App\Models\Lead::class)
                <x-nav-link icon="users-2" :href="route('leads.index')" :active="request()->routeIs('leads.*')" :badge="$visibleLeadCount">Leads</x-nav-link>
                <x-nav-link icon="kanban" :href="route('pipeline.index')" :active="request()->routeIs('pipeline.*')">Pipeline</x-nav-link>
            @endcan
            <x-nav-link icon="calendar-check-2" :href="route('follow-ups.index')" :active="request()->routeIs('follow-ups.*')" :badge="$dueCount ?: null" :highlight="$dueCount > 0">Follow-Ups</x-nav-link>
            @can('import-leads')
                <x-nav-link icon="upload-cloud" :href="route('imports.index')" :active="request()->routeIs('imports.*')">Imports</x-nav-link>
            @endcan
            @can('view-reports')
                <x-nav-link icon="bar-chart-3" :href="route('reports.index')" :active="request()->routeIs('reports.*')">Reports</x-nav-link>
            @endcan

            @canany(['view-users', 'manage-settings', 'view-audit-log'])
                <div class="space-y-0.5 pt-4">
                    <p class="px-2.5 py-1.5 text-3xs font-bold tracking-wider text-slate-500 uppercase">Admin &amp; Org</p>
                    @can('view-users')
                        <x-nav-link icon="shield-check" :href="route('users.index')" :active="request()->routeIs('users.*', 'teams.*')">
                            {{ $user->can('manage-users') ? 'Users & Teams' : 'Team Directory' }}
                        </x-nav-link>
                    @endcan
                    @can('manage-settings')
                        <x-nav-link icon="settings" :href="route('settings.index')" :active="request()->routeIs('settings.*')">Settings</x-nav-link>
                    @endcan
                    @can('view-audit-log')
                        <x-nav-link icon="history" :href="route('audit-log.index')" :active="request()->routeIs('audit-log.*')">Audit Log</x-nav-link>
                    @endcan
                </div>
            @endcanany
        </nav>

        <div class="space-y-2 border-t border-slate-200 p-3">
            <a href="{{ route('profile.show') }}" class="group flex min-w-0 items-center gap-2 rounded-lg p-1.5 text-left transition hover:bg-slate-100">
                <x-avatar :user="$user" size="md" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-semibold text-slate-800 group-hover:text-indigo-600">{{ $user->name }}</span>
                    <span class="block truncate text-2xs text-slate-500">{{ $user->team?->name ?? $user->role->label() }}</span>
                </span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="group flex w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                    <x-lucide-log-out class="size-3.25 text-slate-400 group-hover:text-rose-600" aria-hidden="true" />
                    <span>Log Out</span>
                </button>
            </form>
        </div>
    </aside>

    <div class="flex min-h-screen flex-col lg:pl-60">
        {{-- Header --}}
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-6">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <button type="button" x-on:click="toggle" aria-controls="sidebar" x-bind:aria-expanded="open"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden">
                    <span class="sr-only">Menu</span>
                    <x-lucide-menu class="size-5" aria-hidden="true" />
                </button>

                @can('viewAny', App\Models\Lead::class)
                    <form method="GET" action="{{ route('leads.index') }}" role="search" x-data="globalSearch"
                          data-live-url="{{ route('leads.live-search') }}" x-on:click.outside="close"
                          x-on:keydown.window="shortcut" class="relative w-full max-w-md">
                        <label for="global-search" class="sr-only">Search leads</label>
                        <x-lucide-search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input id="global-search" x-ref="input" type="search" name="q" value="{{ request()->routeIs('leads.index') ? request('q') : '' }}"
                               x-on:keydown.escape="blur" x-on:input.debounce.250ms="lookup" x-on:focus="reopen" autocomplete="off"
                               aria-controls="global-search-results" x-bind:aria-expanded="open"
                               placeholder="Search leads by name, company, email, phone, ID… (Press '/')"
                               class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pr-12 pl-9 text-xs transition-all placeholder:text-slate-500 hover:bg-slate-100/70 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
                        <kbd class="absolute top-1/2 right-3 -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-3xs text-slate-500 shadow-2xs" aria-hidden="true">/</kbd>
                        <div id="global-search-results" x-ref="results" x-cloak x-show="open" aria-live="polite"
                             class="absolute top-full right-0 left-0 z-50 mt-1.5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"></div>
                    </form>
                @endcan
            </div>

            <div class="flex items-center gap-3">
                {{-- Alerts bell (prototype "Follow-Up Alerts"), computed per request --}}
                <x-dropdown width="w-80">
                    <x-slot:trigger>
                        <button type="button" class="relative rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-haspopup="true">
                            <span class="sr-only">Alerts: {{ $alerts['total'] }}</span>
                            <x-lucide-bell class="size-4.5" aria-hidden="true" />
                            @if ($alerts['total'] > 0)
                                <span class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-4xs font-bold text-white" aria-hidden="true">{{ $alerts['total'] > 99 ? '99+' : $alerts['total'] }}</span>
                            @endif
                        </button>
                    </x-slot:trigger>
                    <div class="-my-1 overflow-hidden rounded-xl">
                        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 p-3">
                            <p class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
                                <x-lucide-clock class="size-3.5 text-indigo-600" aria-hidden="true" />
                                <span>Follow-Up Alerts</span>
                            </p>
                            <span class="text-2xs font-medium text-slate-500">{{ $alerts['total'] }} pending</span>
                        </div>
                        @if ($alerts['total'] === 0)
                            <p class="p-6 text-center text-xs text-slate-500">No alerts. You're all caught up.</p>
                        @else
                            <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                                @foreach ([['overdue', 'Overdue', 'red'], ['today', 'Due Today', 'amber']] as [$key, $label, $tone])
                                    @foreach ($alerts[$key] as $fu)
                                        <a href="{{ route('leads.show', $fu->lead) }}?tab=followups" class="block p-3 text-left transition hover:bg-slate-50">
                                            <span class="mb-1 flex items-center justify-between gap-2">
                                                <span class="truncate text-xs font-semibold text-slate-900">{{ $fu->lead->displayName() }}</span>
                                                <x-badge :tone="$tone">{{ $label }}</x-badge>
                                            </span>
                                            <span class="line-clamp-1 block text-2xs text-slate-600">{{ $fu->notes ?: $fu->type->label() }}</span>
                                            <span class="mt-1 flex items-center justify-between text-3xs text-slate-500"><span>{{ $fu->lead->company }}</span><x-date :value="$fu->due_at" /></span>
                                        </a>
                                    @endforeach
                                @endforeach
                                @foreach ($alerts['assigned'] as $lead)
                                    <a href="{{ route('leads.show', $lead) }}" class="block p-3 text-left transition hover:bg-slate-50">
                                        <span class="mb-1 flex items-center justify-between gap-2">
                                            <span class="truncate text-xs font-semibold text-slate-900">{{ $lead->displayName() }}</span>
                                            <x-badge tone="indigo">Assigned to you</x-badge>
                                        </span>
                                        <span class="text-3xs text-slate-500">{{ $lead->company }} · {{ $lead->reference }}</span>
                                    </a>
                                @endforeach
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50/70 p-2.5 px-3">
                                <a href="{{ route('follow-ups.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">View all follow-ups</a>
                                @if ($alerts['counts']['assigned'] > 0)
                                    <form method="POST" action="{{ route('alerts.seen') }}">
                                        @csrf
                                        <button type="submit" class="rounded px-1.5 py-0.5 text-xs font-medium text-slate-600 hover:bg-white hover:text-slate-900">Mark all as seen</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-dropdown>

                <div class="hidden items-center gap-2 border-l border-slate-200 pl-2 text-xs sm:flex">
                    <span class="text-2xs text-slate-500">Logged in as:</span>
                    <a href="{{ route('profile.show') }}" class="flex items-center gap-2 rounded-lg p-1.5 transition hover:bg-slate-100">
                        <x-avatar :user="$user" />
                        <span class="font-semibold text-slate-800">{{ $user->name }}</span>
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-3xs font-bold text-slate-600 uppercase">{{ $user->role->value }}</span>
                    </a>
                </div>

                @if ($canCreateLead)
                    <x-button :href="route('leads.create')" icon="plus" class="active:scale-95">Add Lead</x-button>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="group flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600">
                        <x-lucide-log-out class="size-3.5 text-slate-400 group-hover:text-rose-600" aria-hidden="true" />
                        <span class="hidden sm:inline">Logout</span>
                        <span class="sr-only sm:hidden">Logout</span>
                    </button>
                </form>
            </div>
        </header>

        <main id="main" class="flex-1 bg-slate-50/60">
            <div class="{{ $maxWidth }} mx-auto space-y-6 p-4 sm:p-8">
                <x-flash />
                @if ($header?->isNotEmpty())
                    {{ $header }}
                @else
                    <x-page-header :title="$title" :subtitle="$subtitle" :count="$count" :actions="$actions" :more="$more" />
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
</div>
</body>
</html>
