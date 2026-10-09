{{-- Shared settings shell: prototype SettingsView header ("Admin Only" chip) + pill sub-tabs. --}}
<x-layouts.app title="CRM Configuration & Settings" width="5xl"
                subtitle="Customize lead statuses, sources, tags, and standard user visibility policies">
    <x-slot:badge>
        <span class="rounded border border-rose-200 bg-rose-50 px-2 py-0.5 text-3xs font-bold tracking-wider text-rose-700 uppercase">Admin Only</span>
    </x-slot:badge>

    <div class="border-b border-slate-200 pb-2">
        <x-tabs variant="subnav" label="Settings sections" :tabs="[
            'statuses' => ['label' => 'Lead Statuses (Pipeline)', 'href' => route('settings.statuses'), 'active' => request()->routeIs('settings.index', 'settings.statuses')],
            'sources' => ['label' => 'Lead Sources', 'href' => route('settings.sources'), 'active' => request()->routeIs('settings.sources')],
            'tags' => ['label' => 'Tags & Labels', 'href' => route('settings.tags'), 'active' => request()->routeIs('settings.tags')],
            'general' => ['label' => 'Security & Rules', 'href' => route('settings.general'), 'active' => request()->routeIs('settings.general')],
            'permissions' => ['label' => 'Permissions', 'href' => route('settings.permissions'), 'active' => request()->routeIs('settings.permissions')],
        ]" />
    </div>

    {{ $slot }}
</x-layouts.app>
