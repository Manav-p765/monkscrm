<x-settings-layout>
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Role &amp; Access Control Matrix</h2>
            <p class="mt-0.5 text-xs text-slate-500">What each role can do. Generated from the same rules the system enforces, so it is always accurate. Read-only.</p>
        </div>
        @include('settings._permission-matrix')
    </div>
</x-settings-layout>
