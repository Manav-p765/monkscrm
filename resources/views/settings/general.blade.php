<x-settings-layout>
    <form method="POST" action="{{ route('settings.general.update') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs" novalidate>
        @csrf @method('PUT')

        <section class="space-y-4">
            <div>
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Standard User Visibility Policy</h2>
                <p class="mt-0.5 text-xs text-slate-500">Control which leads Sales users can see. Admins and managers always see every lead.</p>
            </div>
            <x-checkbox name="sales_can_view_all_leads" label="Allow Sales users to view leads assigned to other team members"
                        :checked="$settings['sales_can_view_all_leads'] ?? false" hint="Off: Sales see only their own leads (and unassigned leads, if allowed below)." />
            <x-checkbox name="sales_can_view_unassigned" label="Allow Sales users to view unassigned leads"
                        :checked="$settings['sales_can_view_unassigned'] ?? true" hint="Lets Sales pick up new leads nobody owns yet." />
        </section>

        <section class="space-y-4 border-t border-slate-100 pt-5">
            <div>
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Duplicate Record Prevention</h2>
                <p class="mt-0.5 text-xs text-slate-500">Leads are matched on email and phone (digits only) when added by hand or imported. Sales can never save a possible duplicate.</p>
            </div>
            <x-checkbox name="allow_duplicate_override" label="Admins and managers can save possible duplicates"
                        :checked="$settings['allow_duplicate_override'] ?? true" hint="Every override is recorded in the audit log." />
        </section>

        <section class="space-y-4 border-t border-slate-100 pt-5">
            <div>
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Currencies</h2>
                <p class="mt-0.5 text-xs text-slate-500">Deal values are stored per lead in their own currency and never converted. Turning a currency off hides it for new values; existing leads keep it.</p>
            </div>
            <fieldset>
                <legend class="mb-2 text-xs font-semibold text-slate-700">Enabled currencies</legend>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($currencies as $currency)
                        <x-checkbox name="enabled_currencies[]" :value="$currency->value" :label="$currency->symbol().' · '.$currency->value"
                                    :hint="$currency->label()" :checked="in_array($currency->value, $enabled, true)" />
                    @endforeach
                </div>
                @error('enabled_currencies')
                    <p class="mt-1 text-2xs font-medium text-rose-700">{{ $message }}</p>
                @enderror
            </fieldset>
            <div class="max-w-xs">
                <x-select name="default_currency" label="Default currency for new leads" :options="collect($currencies)->mapWithKeys(fn ($c) => [$c->value => $c->value.' · '.$c->label()])"
                          :value="$defaultCurrency" required hint="Must be one of the enabled currencies." />
            </div>
        </section>

        <section class="space-y-4 border-t border-slate-100 pt-5">
            <div>
                <h2 class="text-xs font-bold tracking-wider text-slate-900 uppercase">Import Limits</h2>
                <p class="mt-0.5 text-xs text-slate-500">Largest CSV or Excel file and most rows accepted in one import.</p>
            </div>
            <div class="grid max-w-md grid-cols-2 gap-3">
                <x-input name="import_max_file_mb" type="number" min="1" max="20" label="Max file size (MB)" :value="$settings['import_max_file_mb'] ?? 5" required hint="Up to 20 MB." />
                <x-input name="import_max_rows" type="number" min="100" max="20000" label="Max rows per import" :value="$settings['import_max_rows'] ?? 5000" required hint="Up to 20,000." />
            </div>
        </section>

        <div class="flex justify-end border-t border-slate-100 pt-4">
            <x-button icon="check">Save Settings</x-button>
        </div>
    </form>
</x-settings-layout>
