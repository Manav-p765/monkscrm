@php
    $back = fn (string $step) => route('imports.show', [$import, 'step' => $step]);
    $navBtn = 'px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg flex items-center gap-1.5';
@endphp

<x-layouts.app title="Bulk Lead Import" :subtitle="$import->original_filename">
    <x-slot:actions>
        <x-button variant="secondary" :href="route('imports.index', ['tab' => 'history'])">Past Imports</x-button>
        @if (in_array($import->status, ['mapping', 'validated', 'ready'], true))
            <x-confirm-dialog trigger="Cancel Import" trigger-variant="ghost" :action="route('imports.cancel', $import)"
                              title="Cancel the import of “{{ $import->original_filename }}”?" confirm="Cancel Import" loading="Cancelling…">
                Nothing has been imported yet. The rows read from the file are deleted.
            </x-confirm-dialog>
        @endif
    </x-slot:actions>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs">
        @include('imports._steps', ['current' => $step])

        <div class="space-y-6 p-6 sm:p-8">
            {{-- STEP 2: Read --}}
            @if ($step === 'read')
                <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center gap-3">
                        <x-lucide-file-spreadsheet class="size-6 text-emerald-600" aria-hidden="true" />
                        <div>
                            <h2 class="text-xs font-bold text-slate-900">{{ $import->original_filename }}</h2>
                            <p class="text-2xs text-slate-500">Read {{ number_format($import->total_rows) }} rows and {{ count($headers) }} columns</p>
                        </div>
                    </div>
                </div>
                <div>
                    <h3 class="mb-2 text-xs font-bold text-slate-800">Sample Preview (First 3 Rows)</h3>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full divide-y divide-slate-200 text-left text-xs">
                            <thead class="bg-slate-50 font-semibold text-slate-600"><tr>@foreach ($headers as $h)<th class="px-3 py-2.5 whitespace-nowrap">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($sample as $row)
                                    <tr>@foreach ($headers as $h)<td class="px-3 py-2 whitespace-nowrap text-slate-700">{{ ($row->data[$h] ?? '') !== '' ? $row->data[$h] : '—' }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-slate-200 pt-4">
                    <a href="{{ route('imports.index') }}" class="{{ $navBtn }}"><x-lucide-arrow-left class="size-3.5" aria-hidden="true" /> Choose a Different File</a>
                    <x-button :href="$back('map')">Proceed to Field Mapping <x-lucide-arrow-right class="size-3.5" aria-hidden="true" /></x-button>
                </div>
            @endif

            {{-- STEP 3: Map --}}
            @if ($step === 'map')
                <form method="POST" action="{{ route('imports.mapping', $import) }}" class="space-y-6" novalidate>
                    @csrf
                    <div>
                        <h2 class="text-xs font-bold text-slate-900">Step 3: Map Spreadsheet Columns to CRM Fields</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Match each column from your file to a CRM field. Choose “Do Not Import” for columns to ignore.</p>
                    </div>
                    @error('mapping')
                        <div role="alert" class="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 p-2.5 px-4 text-xs font-semibold text-rose-700">
                            <x-lucide-alert-triangle class="size-4 shrink-0 text-rose-600" aria-hidden="true" /><span>{{ $message }}</span>
                        </div>
                    @enderror
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200">
                        <div class="grid grid-cols-12 bg-slate-50 p-3 text-xs font-semibold text-slate-600">
                            <div class="col-span-5">Uploaded File Column</div><div class="col-span-2 text-center">Sample Value</div><div class="col-span-5">CRM Target Field</div>
                        </div>
                        @foreach ($headers as $i => $header)
                            @php $selected = old("mapping.{$i}", $import->column_mapping[$header] ?? 'none'); @endphp
                            <div class="grid grid-cols-12 items-center gap-3 p-3 text-xs hover:bg-slate-50/50">
                                <label for="map-{{ $i }}" class="col-span-5 truncate font-bold text-slate-800">{{ $header }}</label>
                                <div class="col-span-2 truncate text-center font-mono text-2xs text-slate-500">{{ ($sample->first()?->data[$header] ?? '') ?: '—' }}</div>
                                <div class="col-span-5">
                                    <select id="map-{{ $i }}" name="mapping[{{ $i }}]" @class(['w-full rounded-lg border px-3 py-1.5 text-xs transition',
                                        'border-indigo-300 bg-indigo-50/40 font-semibold text-indigo-900' => $selected !== 'none', 'border-slate-200 bg-slate-50 text-slate-600' => $selected === 'none'])>
                                        <option value="none">— Do Not Import —</option>
                                        @foreach ($fields as $key => $label)<option value="{{ $key }}" @selected($selected === $key)>{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-xs font-bold text-slate-900">If the file has values the CRM doesn't know</h3>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach (['status' => ['Unknown status', 'Use the default status'], 'source' => ['Unknown source', 'Use the default source'], 'tags' => ['Unknown tag', 'Leave the tag out'], 'assigned_to' => ['Unknown user', 'Leave unassigned']] as $kind => [$label, $default])
                                @php
                                    $opts = ['default' => $default];
                                    if ($canCreateLookups && $kind !== 'assigned_to') { $opts['create'] = 'Create it'; }
                                    $opts['skip'] = 'Skip the row';
                                @endphp
                                <x-select :name="'unknown['.$kind.']'" :id="'unknown-'.$kind" :label="$label" :options="$opts" :value="$import->options['unknown'][$kind] ?? 'default'" />
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-200 pt-4">
                        <a href="{{ $back('read') }}" class="{{ $navBtn }}"><x-lucide-arrow-left class="size-3.5" aria-hidden="true" /> Back</a>
                        <x-button loading="Validating…">Proceed to Validation <x-lucide-arrow-right class="size-3.5" aria-hidden="true" /></x-button>
                    </div>
                </form>
            @endif

            {{-- STEP 4: Validate --}}
            @if ($step === 'validate')
                <div>
                    <h2 class="text-xs font-bold text-slate-900">Step 4: Data Validation &amp; Quality Audit</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Email formats, phone numbers, required fields, unknown values and duplicates.</p>
                </div>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <x-stat label="Total Rows">{{ number_format($import->total_rows) }}</x-stat>
                    <x-stat label="Ready &amp; Valid" tone="emerald">{{ number_format($import->valid_rows) }}</x-stat>
                    <x-stat label="Potential Duplicates" tone="amber">{{ number_format($import->duplicate_rows) }}</x-stat>
                    <x-stat label="Invalid / Malformed" tone="rose">{{ number_format($import->invalid_rows) }}</x-stat>
                </div>
                @if ($import->invalid_rows > 0)
                    <div class="space-y-2 rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-bold text-rose-900">
                            <span class="flex items-center gap-1.5"><x-lucide-alert-triangle class="size-3.75 text-rose-600" aria-hidden="true" />{{ $import->invalid_rows }} rows need attention and won't be imported</span>
                            <a href="{{ route('imports.errors', $import) }}" class="underline">Download these rows (CSV)</a>
                        </div>
                        <ul class="list-disc space-y-1 pl-5 text-xs text-rose-800">
                            @foreach ($invalidSample as $r)<li>Row {{ $r->row_number }}: {{ implode(', ', $r->errors ?? []) }}</li>@endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex items-center justify-between border-t border-slate-200 pt-4">
                    <a href="{{ $back('map') }}" class="{{ $navBtn }}"><x-lucide-arrow-left class="size-3.5" aria-hidden="true" /> Back to Mapping</a>
                    <x-button :href="$back('duplicates')">Configure Duplicate Handling <x-lucide-arrow-right class="size-3.5" aria-hidden="true" /></x-button>
                </div>
            @endif

            {{-- STEP 5: Duplicates --}}
            @if ($step === 'duplicates')
                <form method="POST" action="{{ route('imports.strategy', $import) }}" class="space-y-6" novalidate>
                    @csrf
                    <div>
                        <h2 class="text-xs font-bold text-slate-900">Step 5: Select Duplicate Handling Strategy</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $import->duplicate_rows }} rows share an email or phone with an existing lead or an earlier row in the file.</p>
                    </div>
                    <fieldset class="space-y-3">
                        <legend class="sr-only">Duplicate handling</legend>
                        @foreach ([
                            'skip' => ['Skip duplicates (Recommended)', 'Keep existing leads as they are and leave out rows that share an email or phone.', 'Safest Default'],
                            'update' => ['Update existing leads with the file’s data', 'Fills in the existing lead with values from the file (only leads you can see; history is kept). Careful: Undo import can’t reverse these updates.', 'Merge & Overwrite'],
                            'new' => ['Import all rows as new leads anyway', 'Creates a new lead even when one with the same email or phone exists.', 'May Create Clones'],
                        ] as $value => [$title, $desc, $badge])
                            @php $checked = old('strategy', $import->duplicate_strategy ?? 'skip') === $value; @endphp
                            <label class="flex cursor-pointer items-start gap-4 rounded-xl border border-slate-200 p-4 transition hover:bg-slate-50 has-checked:border-indigo-600 has-checked:bg-indigo-50/30 has-checked:ring-1 has-checked:ring-indigo-600">
                                <input type="radio" name="strategy" value="{{ $value }}" @checked($checked) @disabled($value !== 'skip' && ! $canPickStrategy) class="mt-1 accent-indigo-600">
                                <span class="flex-1">
                                    <span class="flex items-center gap-2"><span class="text-xs font-bold text-slate-900">{{ $title }}</span><span class="rounded bg-slate-100 px-2 py-0.5 text-3xs font-semibold text-slate-600">{{ $badge }}</span></span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $desc }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="flex items-center justify-between border-t border-slate-200 pt-4">
                        <a href="{{ $back('validate') }}" class="{{ $navBtn }}"><x-lucide-arrow-left class="size-3.5" aria-hidden="true" /> Back</a>
                        <x-button loading="Preparing…">Final Preview &amp; Confirm <x-lucide-arrow-right class="size-3.5" aria-hidden="true" /></x-button>
                    </div>
                </form>
            @endif

            {{-- STEP 6: Preview, import (chunked), result --}}
            @if ($step === 'import')
                @if ($import->status === 'completed' || $import->status === 'undone')
                    <div class="space-y-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-8 text-center">
                        <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-600 text-white shadow-md"><x-lucide-check-circle-2 class="size-8" aria-hidden="true" /></div>
                        <div>
                            <h2 class="text-base font-bold text-emerald-950">{{ $import->status === 'undone' ? 'Import Undone' : 'Import Completed Successfully!' }}</h2>
                            <p class="mt-1 text-xs text-emerald-800">Processed {{ number_format($import->total_rows) }} rows from {{ $import->original_filename }}</p>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-6 pt-2">
                            <div><div class="text-2xl font-bold text-emerald-900">{{ number_format($import->imported_rows) }}</div><div class="text-xs text-emerald-700">New Leads Added</div></div>
                            <div><div class="text-2xl font-bold text-indigo-900">{{ number_format($import->updated_rows) }}</div><div class="text-xs text-indigo-700">Existing Updated</div></div>
                            <div><div class="text-2xl font-bold text-slate-700">{{ number_format($import->skipped_rows) }}</div><div class="text-xs text-slate-600">Duplicates Skipped</div></div>
                            <div><div class="text-2xl font-bold text-rose-800">{{ number_format($import->invalid_rows) }}</div><div class="text-xs text-rose-700">Not Imported (Errors)</div></div>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-3 pt-4">
                            @if ($import->invalid_rows)<x-button variant="secondary" icon="download" :href="route('imports.errors', $import)">Download Rows Not Imported</x-button>@endif
                            <x-button variant="secondary" :href="route('imports.index')">Start Another Import</x-button>
                            <x-button :href="route('imports.index', ['tab' => 'history'])">View Import History Log</x-button>
                        </div>
                    </div>
                @elseif ($import->status === 'cancelled')
                    <x-empty-state title="This import was cancelled" icon="x">Nothing was imported.</x-empty-state>
                @else
                    <div class="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs">
                        <span><strong>{{ number_format($counts['create']) }}</strong> new leads</span><span>·</span>
                        <span><strong>{{ number_format($counts['update']) }}</strong> updates</span><span>·</span>
                        <span><strong>{{ number_format($counts['skip']) }}</strong> duplicates skipped</span><span>·</span>
                        <span><strong>{{ number_format($counts['error']) }}</strong> with errors</span><span>·</span>
                        <span>Strategy: <strong class="capitalize">{{ $import->duplicate_strategy }}</strong></span>
                    </div>

                    <div class="max-h-72 overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full divide-y divide-slate-200 text-left text-xs">
                            <thead class="sticky top-0 bg-slate-50 font-semibold text-slate-600"><tr><th class="px-3 py-2.5">#</th>@foreach (array_slice($headers, 0, 5) as $h)<th class="px-3 py-2.5">{{ $h }}</th>@endforeach<th class="px-3 py-2.5">Will be</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($sample as $r)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-2xs text-slate-500">{{ $r->row_number }}</td>
                                        @foreach (array_slice($headers, 0, 5) as $h)<td class="px-3 py-2 text-slate-600">{{ ($r->data[$h] ?? '') ?: '—' }}</td>@endforeach
                                        <td class="px-3 py-2">
                                            @switch($r->action)
                                                @case('create') @case('create_dup') <x-badge tone="green">New lead</x-badge> @break
                                                @case('update') @case('updated') <x-badge tone="indigo">Update</x-badge> @break
                                                @case('error') <x-badge tone="red">Error</x-badge> @break
                                                @case('created') <x-badge tone="green">Imported</x-badge> @break
                                                @default <x-badge tone="amber">Skipped</x-badge>
                                            @endswitch
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div x-data="importRunner" data-url="{{ route('imports.run', $import) }}" data-done-url="{{ route('imports.show', [$import, 'step' => 'import']) }}"
                         data-autostart="{{ $import->status === 'importing' ? 'true' : 'false' }}" class="space-y-3 border-t border-slate-200 pt-4">
                        <div x-cloak x-show="running" class="space-y-2" role="status">
                            <p class="text-xs font-semibold text-slate-800" x-text="progressText">Importing…</p>
                            <svg class="block h-2.5 w-full" viewBox="0 0 100 2.5" preserveAspectRatio="none" aria-hidden="true">
                                <rect width="100" height="2.5" rx="1.25" class="fill-slate-100" />
                                <rect x-bind:width="percent" height="2.5" rx="1.25" class="fill-emerald-600" />
                            </svg>
                        </div>
                        <p x-cloak x-show="failed" class="text-xs font-medium text-rose-700">The import stopped. Your progress is saved: click “Continue Import” to carry on.</p>
                        <form method="POST" action="{{ route('imports.run', $import) }}" x-on:submit.prevent="start" class="flex items-center justify-between">
                            @csrf
                            <a href="{{ $back('duplicates') }}" class="{{ $navBtn }}"><x-lucide-arrow-left class="size-3.5" aria-hidden="true" /> Back</a>
                            <button type="submit" x-bind:disabled="running" class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-6 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-emerald-700 active:scale-95 disabled:opacity-60">
                                <x-lucide-check-circle-2 class="size-4" aria-hidden="true" />
                                <span>{{ $import->status === 'importing' ? 'Continue Import' : 'Confirm & Import '.number_format($counts['create'] + $counts['update']).' Leads' }}</span>
                            </button>
                        </form>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-layouts.app>
