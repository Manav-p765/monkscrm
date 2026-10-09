<x-layouts.app title="Bulk Lead Import" subtitle="Guided 6-step workflow: inspect headers, map fields, validate formatting, and handle duplicates safely">
    <x-slot:badge><span class="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">CSV &amp; Excel</span></x-slot:badge>
    <x-slot:actions>
        <x-tabs variant="segmented" label="Import views" :tabs="[
            'wizard' => ['label' => 'Import Wizard', 'href' => route('imports.index'), 'active' => $tab === 'wizard'],
            'history' => ['label' => 'Past Imports', 'href' => route('imports.index', ['tab' => 'history']), 'active' => $tab === 'history', 'count' => $history->total()],
        ]" />
        <x-button variant="secondary" icon="download" :href="route('imports.template')">Sample Template</x-button>
    </x-slot:actions>

    @if ($tab === 'history')
        <x-table :paginator="$history" title="Historical Import Log" aside="Who imported what, and when">
            <x-slot:head>
                <tr>
                    <th>File Name</th><th>Import Date</th><th>Imported By</th><th>Strategy</th><th>Status</th>
                    <th class="text-center">Total Rows</th><th class="text-center">Imported</th><th class="text-center">Updated</th><th class="text-center">Skipped</th><th class="text-center">Errors</th><th></th>
                </tr>
            </x-slot:head>
            @forelse ($history as $item)
                <tr>
                    <td class="font-semibold text-slate-800">
                        <a href="{{ route('imports.show', $item) }}" class="flex items-center gap-2 hover:text-indigo-600">
                            <x-lucide-file-spreadsheet class="size-3.75 shrink-0 text-emerald-600" aria-hidden="true" /><span class="truncate">{{ $item->original_filename }}</span>
                        </a>
                    </td>
                    <td class="text-slate-500"><x-date :value="$item->created_at" /></td>
                    <td class="font-medium text-slate-700">{{ $item->user?->name }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-0.5 text-3xs font-bold tracking-wider text-slate-600 uppercase">{{ $item->duplicate_strategy ?? '—' }}</span></td>
                    <td>
                        <x-badge :tone="['completed' => 'green', 'undone' => 'neutral', 'cancelled' => 'neutral', 'importing' => 'amber'][$item->status] ?? 'blue'">{{ ucfirst($item->status) }}</x-badge>
                    </td>
                    <td class="text-center font-bold text-slate-800">{{ $item->total_rows }}</td>
                    <td class="text-center font-bold text-emerald-700">+{{ $item->imported_rows }}</td>
                    <td class="text-center text-indigo-700">{{ $item->updated_rows }}</td>
                    <td class="text-center text-slate-500">{{ $item->skipped_rows }}</td>
                    <td class="text-center text-rose-700">
                        @if ($item->invalid_rows)
                            <a href="{{ route('imports.errors', $item) }}" class="underline hover:text-rose-900">{{ $item->invalid_rows }}</a>
                        @else 0 @endif
                    </td>
                    <td class="text-right">
                        @if ($item->canBeUndone())
                            <x-confirm-dialog trigger="Undo" trigger-variant="danger-soft" trigger-size="sm" :action="route('imports.undo', $item)"
                                              title="Undo the import of “{{ $item->original_filename }}”?" confirm="Undo Import" loading="Undoing…">
                                The {{ $item->imported_rows }} leads it created will be archived, unless someone edited them since (those are kept and listed).
                                Changes it made to existing leads are not reverted.
                            </x-confirm-dialog>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11"><x-empty-state title="No imports yet" icon="upload-cloud">Imports you run will be listed here.</x-empty-state></td></tr>
            @endforelse
        </x-table>
    @else
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs">
            @include('imports._steps', ['current' => 'upload'])
            <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="mx-auto max-w-2xl space-y-6 p-8 py-12 text-center" novalidate
                  x-data="fileDrop">
                @csrf
                <label for="import-file" x-on:dragover.prevent="over" x-on:dragleave="leave" x-on:drop.prevent="drop" x-bind:class="dragClasses"
                       class="group flex cursor-pointer flex-col items-center justify-center space-y-4 rounded-2xl border-2 border-dashed border-slate-300 p-12 transition hover:border-indigo-500 hover:bg-slate-50/50">
                    <span class="flex size-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 transition duration-200 group-hover:scale-110"><x-lucide-upload-cloud class="size-8" aria-hidden="true" /></span>
                    <span>
                        <span class="block text-sm font-bold text-slate-900">Drag &amp; Drop CSV or Excel Spreadsheet</span>
                        <span class="mt-1 block text-xs text-slate-500">Supports <strong class="text-slate-700">.csv</strong> and <strong class="text-slate-700">.xlsx</strong> files (up to {{ number_format($maxRows) }} rows, {{ $maxMb }} MB)</span>
                    </span>
                    <span class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm group-hover:bg-indigo-700">Select File from Computer</span>
                    <span class="text-xs font-medium text-slate-700" x-text="fileName"></span>
                </label>
                <input id="import-file" x-ref="file" type="file" name="file" accept=".csv,.xlsx" class="sr-only" x-on:change="picked">
                @error('file')
                    <p class="flex items-center justify-center gap-1 text-xs font-medium text-rose-700"><x-lucide-alert-circle class="size-3.5" aria-hidden="true" />{{ $message }}</p>
                @enderror
                <div class="flex justify-center">
                    <x-button icon="arrow-right" loading="Reading file…">Upload and Read File</x-button>
                </div>
                <p class="text-2xs text-slate-500">The file is read and then deleted straight away. Rows wait (encrypted) for up to 24 hours until you finish or cancel.</p>
            </form>
        </div>
    @endif
</x-layouts.app>
