<x-layouts.app :title="$section" subtitle="This section is being built">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-2xs">
        <x-empty-state title="Coming in a later step" icon="sparkles">
            This part of {{ config('app.name') }} hasn't been built yet.
            <x-slot:actions>
                <x-button variant="secondary" :href="route('dashboard')">Back to Dashboard</x-button>
            </x-slot:actions>
        </x-empty-state>
    </div>
</x-layouts.app>
