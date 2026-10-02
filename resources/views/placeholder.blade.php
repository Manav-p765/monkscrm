<x-layouts.app :title="$section">
    <x-empty-state title="Coming in a later step">
        This part of {{ config('app.name') }} hasn't been built yet.
        <x-slot:actions>
            <x-button variant="secondary" :href="route('dashboard')">Back to dashboard</x-button>
        </x-slot:actions>
    </x-empty-state>
</x-layouts.app>
