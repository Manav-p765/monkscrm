<x-layouts.app title="Dashboard" width="6xl">
    <x-slot:header>
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-slate-900">Good day, {{ \Illuminate\Support\Str::before($user->name, ' ') ?: $user->name }}</h1>
                <p class="mt-0.5 text-xs text-slate-500">
                    @if ($user->isAdmin())
                        Organization overview &amp; company-wide lead pipeline
                    @elseif ($user->isManager())
                        Operational oversight for your team
                    @else
                        Your assigned leads and scheduled follow-ups
                    @endif
                </p>
            </div>
            @can('viewAny', App\Models\Lead::class)
                <x-button variant="secondary" :href="route('leads.index')">View All Leads</x-button>
            @endcan
        </div>
    </x-slot:header>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-2xs">
        <x-empty-state title="Your dashboard is on its way" icon="layout-dashboard">
            Lead counts, the pipeline, your follow-up queue and recent team activity will appear here.
        </x-empty-state>
    </div>
</x-layouts.app>
