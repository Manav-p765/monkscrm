<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportMappingRequest;
use App\Http\Requests\ImportStrategyRequest;
use App\Http\Requests\ImportUploadRequest;
use App\Models\Import;
use App\Services\Imports\LeadImporter;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import wizard (prototype BulkImportView): Upload → Read → Map → Validate → Duplicates →
 * Preview & Import, plus import history and undo. Admins and managers (route can:import-leads);
 * each import is only visible to its importer and admins.
 */
class ImportController extends Controller
{
    public const STEPS = ['upload' => 'Upload File', 'read' => 'Read File', 'map' => 'Map Fields', 'validate' => 'Validate', 'duplicates' => 'Duplicates', 'import' => 'Preview & Import'];

    public function __construct(private LeadImporter $importer) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $history = Import::query()->with('user:id,name')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()->paginate(25)->withQueryString();

        return view('imports.index', [
            'tab' => $request->query('tab') === 'history' ? 'history' : 'wizard',
            'history' => $history,
            'maxMb' => (int) Settings::get('import_max_file_mb', 5),
            'maxRows' => (int) Settings::get('import_max_rows', 5000),
        ]);
    }

    public function store(ImportUploadRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $import = new Import(['original_filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'status' => 'reading']);
        $import->user_id = $request->user()->id;
        $import->save(); // also purges stale staged rows (no cron)

        try {
            $this->importer->parse($import, $file->getRealPath(), mb_strtolower($file->getClientOriginalExtension()), (int) Settings::get('import_max_rows', 5000));
        } catch (RuntimeException $e) {
            $import->update(['status' => 'cancelled']);

            return redirect()->route('imports.index')->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            $import->update(['status' => 'cancelled']);

            return redirect()->route('imports.index')->withErrors(['file' => 'The file could not be read. Check it opens in Excel or Google Sheets and try again.']);
        } finally {
            @unlink($file->getRealPath()); // never keep the upload
        }

        Audit::log('import.started', $import, ['rows' => $import->total_rows]);

        return redirect()->route('imports.show', [$import, 'step' => 'read']);
    }

    public function show(Request $request, Import $import): View
    {
        $this->authorizeImport($request, $import);

        $step = match ($import->status) {
            'mapping' => in_array($request->query('step'), ['read', 'map'], true) ? $request->query('step') : 'read',
            'validated' => in_array($request->query('step'), ['map', 'validate', 'duplicates'], true) ? $request->query('step') : 'validate',
            'ready' => in_array($request->query('step'), ['map', 'duplicates', 'import'], true) ? $request->query('step') : 'import',
            default => 'import',
        };

        $rows = $import->rows();

        return view('imports.show', [
            'import' => $import,
            'step' => $step,
            'headers' => array_keys($import->column_mapping ?? []),
            'sample' => in_array($step, ['read', 'map', 'import'], true) ? $import->rows()->orderBy('row_number')->limit($step === 'import' ? 8 : 3)->get() : collect(),
            'invalidSample' => $step === 'validate' ? $import->rows()->where('action', 'error')->orderBy('row_number')->limit(5)->get() : collect(),
            'counts' => $import->status === 'ready' || $import->status === 'importing' ? [
                'create' => (clone $rows)->whereIn('action', ['create', 'create_dup'])->count(),
                'update' => (clone $rows)->where('action', 'update')->count(),
                'skip' => (clone $rows)->where('action', 'skip_dup')->count(),
                'error' => (clone $rows)->where('action', 'error')->count(),
                'remaining' => (clone $rows)->whereIn('action', ['create', 'create_dup', 'update'])->count(),
            ] : null,
            'fields' => LeadImporter::FIELDS,
            'canCreateLookups' => $request->user()->isAdmin(),
            'canPickStrategy' => Permissions::allows($request->user(), 'import-leads'),
        ]);
    }

    public function mapping(ImportMappingRequest $request, Import $import): RedirectResponse
    {
        $this->authorizeImport($request, $import, ['mapping', 'validated', 'ready']);
        $headers = array_keys($import->column_mapping ?? []);
        $data = $request->validated();

        $mapping = [];
        foreach ($headers as $i => $header) {
            $mapping[$header] = $data['mapping'][$i] ?? 'none';
        }
        $chosen = array_filter($mapping, fn ($f) => $f !== 'none');
        if (count($chosen) !== count(array_unique($chosen))) {
            return back()->withErrors(['mapping' => 'Each CRM field can be used once. Set duplicates to “Do Not Import”.'])->withInput();
        }
        if (! array_intersect($chosen, ['first_name', 'full_name', 'company']) || ! array_intersect($chosen, ['email', 'phone'])) {
            return back()->withErrors(['mapping' => 'Map at least a name (or company) column and an email (or phone) column.'])->withInput();
        }

        $import->update(['column_mapping' => $mapping, 'options' => ['unknown' => array_intersect_key($data['unknown'] ?? [], LeadImporter::UNKNOWN_KINDS)]]);
        $this->importer->validate($import->fresh(), $request->user());

        return redirect()->route('imports.show', [$import, 'step' => 'validate']);
    }

    public function strategy(ImportStrategyRequest $request, Import $import): RedirectResponse
    {
        $this->authorizeImport($request, $import, ['validated', 'ready']);
        $data = $request->validated();

        // Updating existing leads or importing duplicates as new is admin/manager only.
        if ($data['strategy'] !== 'skip' && ! Permissions::allows($request->user(), 'assign-leads')) {
            abort(403);
        }

        $this->importer->applyStrategy($import, $request->user(), $data['strategy']);

        return redirect()->route('imports.show', [$import, 'step' => 'import']);
    }

    /**
     * Import the next chunk. The page calls this repeatedly ("Importing 1,500 of 4,200") until done;
     * reloading the page resumes. Answers plain text "done/total" to the page's fetch.
     */
    public function run(Request $request, Import $import): Response|RedirectResponse
    {
        $this->authorizeImport($request, $import, ['ready', 'importing']);
        $done = $this->importer->runChunk($import, $request->user());
        $import->refresh();

        if ($request->header('X-Requested-With') === 'fetch') {
            return response($done ? 'done' : $import->processed_rows.'/'.max(1, $import->processed_rows + $import->rows()->whereIn('action', ['create', 'create_dup', 'update'])->count()),
                200, ['Content-Type' => 'text/plain']);
        }

        return redirect()->route('imports.show', [$import, 'step' => 'import']);
    }

    public function cancel(Request $request, Import $import): RedirectResponse
    {
        $this->authorizeImport($request, $import, ['reading', 'mapping', 'validated', 'ready']);
        $import->update(['status' => 'cancelled']);
        Audit::log('import.cancelled', $import);

        return redirect()->route('imports.index')->with('status', 'Import cancelled. Nothing was imported.');
    }

    public function undo(Request $request, Import $import): RedirectResponse
    {
        $this->authorizeImport($request, $import);
        if (! $import->canBeUndone()) {
            return back()->with('error', 'This import can no longer be undone (undo is possible for '.Import::UNDO_HOURS.' hours).');
        }

        $result = $this->importer->undo($import, $request->user());
        $kept = count($result['kept']);

        return redirect()->route('imports.index', ['tab' => 'history'])->with('status',
            "{$result['archived']} imported ".str('lead')->plural($result['archived']).' archived'
            .($kept ? " · {$kept} kept because they were edited since: ".implode(', ', array_slice($result['kept'], 0, 10)).($kept > 10 ? '…' : '') : ''));
    }

    /** Rows that couldn't be imported, with the original values and the reason. */
    public function errors(Request $request, Import $import): StreamedResponse
    {
        $this->authorizeImport($request, $import);
        $rows = $import->status === 'completed' || $import->status === 'undone'
            ? collect($import->error_rows ?? [])
            : $import->rows()->where('action', 'error')->orderBy('row_number')->get()->map(fn ($r) => ['row' => $r->row_number, 'errors' => $r->errors, 'data' => $r->data]);

        return response()->streamDownload(function () use ($rows, $import) {
            $out = fopen('php://output', 'w');
            $headers = array_keys($import->column_mapping ?? []);
            Csv::write($out, ['Row', 'Problem', ...$headers]);
            foreach ($rows as $r) {
                Csv::write($out, [$r['row'], implode('; ', $r['errors'] ?? []), ...array_map(fn ($h) => $r['data'][$h] ?? '', $headers)]);
            }
            fclose($out);
        }, 'import-'.$import->id.'-rows-not-imported.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function template(): StreamedResponse
    {
        Gate::authorize('import-leads');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            Csv::write($out, ['First Name', 'Last Name', 'Company', 'Job Title', 'Email', 'Phone', 'Status', 'Source', 'Priority', 'Assigned To', 'Tags', 'Deal Value', 'Currency', 'City', 'State', 'Country', 'Notes']);
            Csv::write($out, ['Priya', 'Shah', 'Acme Pvt Ltd', 'Head of Operations', 'priya.shah@example.com', '+91 98765 43210', 'New', 'Referral', 'High', '', 'Hot Lead', '250000', 'INR', 'Pune', 'Maharashtra', 'India', 'Met at the expo']);
            fclose($out);
        }, 'monks-crm-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Only the importer and admins see an import; optionally require one of these statuses. */
    private function authorizeImport(Request $request, Import $import, ?array $statuses = null): void
    {
        $user = $request->user();
        abort_unless($import->user_id === $user->id || $user->isAdmin(), 404);
        if ($statuses !== null && ! in_array($import->status, $statuses, true)) {
            abort(redirect()->route('imports.show', $import)->with('error', 'This import has moved on. Here is where it is now.'));
        }
    }
}
