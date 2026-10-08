<?php

namespace App\Services\Imports;

use App\Enums\ActivityType;
use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Enums\LeadStatusType;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\LeadAssigner;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\Settings;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * The import wizard's engine (prototype BulkImportView): parse → map → validate → duplicates →
 * import in chunks → undo. Rows are staged encrypted in import_rows between steps; the uploaded
 * file is deleted right after parsing. Status changes go through LeadStatusChanger and assignment
 * changes through LeadAssigner. Updates only touch leads the importer can see.
 */
class LeadImporter
{
    public const CHUNK = 500;

    /** CRM fields a column can be mapped to (prototype CRM_FIELDS + ours). */
    public const FIELDS = [
        'full_name' => 'Full Name', 'first_name' => 'First Name', 'last_name' => 'Last Name',
        'company' => 'Company / Organization', 'job_title' => 'Job Title',
        'email' => 'Primary Email', 'secondary_email' => 'Secondary Email',
        'phone' => 'Primary Phone', 'secondary_phone' => 'Secondary Phone', 'website' => 'Website',
        'status' => 'Lead Status', 'source' => 'Lead Source', 'priority' => 'Priority',
        'assigned_to' => 'Assigned To (email or name)', 'tags' => 'Tags (comma separated)',
        'deal_value' => 'Deal Value', 'currency' => 'Currency',
        'address_line1' => 'Street Address', 'city' => 'City', 'state' => 'State / Province',
        'postal_code' => 'Postal / ZIP Code', 'country' => 'Country', 'description' => 'Notes / Description',
    ];

    /** Unknown-value kinds and the choices for each (create is admin-only, users can't be created). */
    public const UNKNOWN_KINDS = ['status' => 'statuses', 'source' => 'sources', 'tags' => 'tags', 'assigned_to' => 'users'];

    public function __construct(private LeadStatusChanger $statuses, private LeadAssigner $assigner) {}

    // ---- 1. Upload & read --------------------------------------------------------------------

    /**
     * Read the file into staged rows. The caller deletes the uploaded file right after.
     *
     * @return array{headers: list<string>, rows: int}
     */
    public function parse(Import $import, string $path, string $extension, int $maxRows): array
    {
        $reader = $extension === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $headers = null;
        $count = 0;
        $batch = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => $this->cellToString($v), $row->toArray());
                    if (! array_filter($cells, fn ($v) => $v !== '')) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = $this->uniqueHeaders($cells);

                        continue;
                    }
                    if (++$count > $maxRows) {
                        throw new RuntimeException("The file has more than {$maxRows} rows. Split it into smaller files and import them one at a time.");
                    }
                    $data = [];
                    foreach ($headers as $i => $header) {
                        $data[$header] = mb_substr($cells[$i] ?? '', 0, 2000);
                    }
                    $batch[] = ['row_number' => $count + 1, 'data' => $data];
                    if (count($batch) === 500) {
                        $this->stage($import, $batch);
                        $batch = [];
                    }
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        if ($headers === null || $count === 0) {
            throw new RuntimeException('The file is empty. Check that it has a header row and at least one lead.');
        }
        $this->stage($import, $batch);

        $import->update(['total_rows' => $count, 'column_mapping' => $this->guessMapping($headers), 'status' => 'mapping']);

        return ['headers' => $headers, 'rows' => $count];
    }

    /** @param list<array{row_number: int, data: array}> $batch */
    private function stage(Import $import, array $batch): void
    {
        foreach ($batch as $row) {
            $import->rows()->create($row); // individual creates so the encrypted cast applies
        }
    }

    private function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => Csv::unescape(trim((string) $value)),
        };
    }

    /** @return list<string> */
    private function uniqueHeaders(array $cells): array
    {
        $seen = [];

        return array_values(array_map(function ($h, $i) use (&$seen) {
            $h = trim((string) $h) ?: 'Column '.($i + 1);
            $name = $h;
            for ($n = 2; isset($seen[mb_strtolower($name)]); $n++) {
                $name = "{$h} ({$n})";
            }
            $seen[mb_strtolower($name)] = true;

            return $name;
        }, $cells, array_keys($cells)));
    }

    /** Auto-guess the CRM field for each header (prototype guessMapping). @return array<string, string> */
    public function guessMapping(array $headers): array
    {
        $used = [];
        $mapping = [];
        foreach ($headers as $header) {
            $h = preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
            $guess = match (true) {
                in_array($h, ['fullname', 'name', 'customername', 'contactname', 'leadname'], true) => 'full_name',
                in_array($h, ['firstname', 'first', 'givenname'], true) => 'first_name',
                in_array($h, ['lastname', 'last', 'surname', 'familyname'], true) => 'last_name',
                str_contains($h, 'company') || str_contains($h, 'organization') || str_contains($h, 'organisation') || $h === 'account' => 'company',
                str_contains($h, 'title') || str_contains($h, 'position') || $h === 'designation' => 'job_title',
                str_contains($h, 'secondaryemail') || str_contains($h, 'altemail') => 'secondary_email',
                str_contains($h, 'email') || $h === 'mail' => 'email',
                str_contains($h, 'secondaryphone') || str_contains($h, 'altphone') => 'secondary_phone',
                str_contains($h, 'phone') || str_contains($h, 'mobile') || str_contains($h, 'tel') || str_contains($h, 'cell') || str_contains($h, 'whatsapp') => 'phone',
                str_contains($h, 'status') || str_contains($h, 'stage') => 'status',
                str_contains($h, 'source') || str_contains($h, 'channel') => 'source',
                str_contains($h, 'priority') => 'priority',
                str_contains($h, 'assigned') || str_contains($h, 'owner') || $h === 'rep' => 'assigned_to',
                str_contains($h, 'tag') => 'tags',
                str_contains($h, 'currency') => 'currency',
                str_contains($h, 'value') || str_contains($h, 'amount') || str_contains($h, 'revenue') || str_contains($h, 'deal') => 'deal_value',
                str_contains($h, 'website') || str_contains($h, 'url') => 'website',
                str_contains($h, 'address') || str_contains($h, 'street') => 'address_line1',
                str_contains($h, 'city') => 'city',
                str_contains($h, 'state') || str_contains($h, 'province') => 'state',
                str_contains($h, 'zip') || str_contains($h, 'postal') || str_contains($h, 'pincode') => 'postal_code',
                str_contains($h, 'country') => 'country',
                str_contains($h, 'note') || str_contains($h, 'desc') || str_contains($h, 'comment') => 'description',
                default => 'none',
            };
            if ($guess !== 'none' && isset($used[$guess])) {
                $guess = 'none';
            }
            $used[$guess] = true;
            $mapping[$header] = $guess;
        }

        return $mapping;
    }

    // ---- 2. Validate & find duplicates -------------------------------------------------------

    /**
     * Validate every staged row against the mapping and find duplicates (in the file and in the
     * database, on normalized email/phone). Stores errors / duplicate per row and the counts.
     */
    public function validate(Import $import, User $importer): void
    {
        $mapping = $import->column_mapping ?? [];
        $options = $import->options ?? [];
        $lookups = $this->lookups();
        $seenEmail = [];
        $seenPhone = [];
        $valid = $invalid = $duplicates = 0;

        $import->rows()->orderBy('row_number')->chunkById(500, function ($rows) use ($mapping, $options, $lookups, $importer, &$seenEmail, &$seenPhone, &$valid, &$invalid, &$duplicates) {
            foreach ($rows as $row) {
                [$fields, $errors] = $this->mapRow($row->data, $mapping, $options, $lookups, $importer);
                $email = Lead::normalizeEmail($fields['email'] ?? null);
                $phone = Lead::normalizePhone($fields['phone'] ?? null);
                $phone = $phone !== null && strlen($phone) >= 6 ? $phone : null;

                $dupLeadId = null;
                $inFile = ($email && isset($seenEmail[$email])) || ($phone && isset($seenPhone[$phone]));
                if (! $inFile && ($email || $phone)) {
                    $dupLeadId = Lead::query()->where(function ($q) use ($email, $phone) {
                        if ($email) {
                            $q->orWhere('email_normalized', $email);
                        }
                        if ($phone) {
                            $q->orWhere('phone_normalized', $phone);
                        }
                    })->orderBy('id')->value('id');
                }
                if ($email) {
                    $seenEmail[$email] = true;
                }
                if ($phone) {
                    $seenPhone[$phone] = true;
                }

                $errors === [] ? $valid++ : $invalid++;
                if ($errors === [] && ($inFile || $dupLeadId)) {
                    $duplicates++;
                }

                $row->update([
                    'errors' => $errors === [] ? null : $errors,
                    'duplicate_lead_id' => $dupLeadId,
                    'action' => $errors !== [] ? 'error' : ($inFile ? 'duplicate_in_file' : ($dupLeadId ? 'duplicate' : 'create')),
                ]);
            }
        });

        $import->update(['valid_rows' => $valid, 'invalid_rows' => $invalid, 'duplicate_rows' => $duplicates, 'status' => 'validated']);
    }

    /**
     * Apply the duplicate strategy: skip (default), update (existing leads the importer can see), new.
     */
    public function applyStrategy(Import $import, User $importer, string $strategy): void
    {
        DB::transaction(function () use ($import, $importer, $strategy) {
            $import->rows()->whereIn('action', ['duplicate', 'duplicate_in_file', 'update', 'create_dup', 'skip_dup'])->orderBy('id')
                ->chunkById(500, function ($rows) use ($importer, $strategy) {
                    foreach ($rows as $row) {
                        $isFileDup = $row->duplicate_lead_id === null;
                        $action = match ($strategy) {
                            'new' => 'create_dup',
                            'update' => ! $isFileDup && Lead::query()->visibleTo($importer)->whereKey($row->duplicate_lead_id)->exists() ? 'update' : 'skip_dup',
                            default => 'skip_dup',
                        };
                        $row->update(['action' => $action]);
                    }
                });

            $import->update(['duplicate_strategy' => $strategy, 'status' => 'ready']);
        });
    }

    // ---- 3. Import in chunks -----------------------------------------------------------------

    /**
     * Import the next chunk (up to 500 rows) in one transaction. Resumable: rows already done are
     * marked, so calling again continues where it stopped. Returns true when everything is done.
     */
    public function runChunk(Import $import, User $importer): bool
    {
        $lookups = $this->lookups();

        DB::transaction(function () use ($import, $importer, $lookups) {
            $import->update(['status' => 'importing']);
            $rows = $import->rows()->whereIn('action', ['create', 'create_dup', 'update'])->orderBy('row_number')->limit(self::CHUNK)->lockForUpdate()->get();

            $created = $updated = 0;
            foreach ($rows as $row) {
                [$fields] = $this->mapRow($row->data, $import->column_mapping ?? [], $import->options ?? [], $lookups, $importer);
                if ($row->action === 'update') {
                    $lead = Lead::query()->visibleTo($importer)->find($row->duplicate_lead_id);
                    if ($lead) {
                        $this->updateLead($lead, $fields, $importer);
                        $updated++;
                    }
                    $row->update(['action' => $lead ? 'updated' : 'skip_dup']);
                } else {
                    $this->createLead($import, $fields, $importer);
                    $created++;
                    $row->update(['action' => 'created']);
                }
            }

            $import->forceFill([
                'imported_rows' => $import->imported_rows + $created,
                'updated_rows' => $import->updated_rows + $updated,
                'processed_rows' => $import->processed_rows + $rows->count(),
            ])->save();
        });

        if ($import->rows()->whereIn('action', ['create', 'create_dup', 'update'])->exists()) {
            return false;
        }

        $this->finish($import);

        return true;
    }

    private function finish(Import $import): void
    {
        $errorRows = $import->rows()->where('action', 'error')->orderBy('row_number')->limit(5000)->get()
            ->map(fn (ImportRow $r) => ['row' => $r->row_number, 'errors' => $r->errors, 'data' => $r->data])->all();
        $skipped = $import->rows()->whereIn('action', ['skip_dup', 'duplicate', 'duplicate_in_file'])->count();

        $import->forceFill([
            'skipped_rows' => $skipped,
            'error_rows' => $errorRows ?: null,
            'error_report' => ['errors' => count($errorRows)],
            'completed_at' => now(),
            'status' => 'completed', // the model then deletes the staged rows
        ])->save();

        Audit::log('import.completed', $import, [
            'imported' => $import->imported_rows, 'updated' => $import->updated_rows,
            'skipped' => $skipped, 'errors' => $import->invalid_rows,
        ]);
    }

    private function createLead(Import $import, array $fields, User $importer): void
    {
        // Only real lead columns (mapRow also returns internal flags like status_explicit), so this
        // never depends on mass-assignment protection being on (it's off in seeders and tinker).
        $lead = new Lead(collect($fields)->only(array_diff((new Lead)->getFillable(), ['deal_value']))->all());
        $lead->lead_status_id = $fields['status_id'];
        $lead->lead_source_id = $fields['source_id'];
        $lead->deal_value = $fields['deal_value'] ?? null;
        $lead->created_by = $importer->id;
        $lead->import_id = $import->id;
        $lead->last_activity_at = now();
        $this->statuses->stampMilestone($lead, LeadStatus::find($fields['status_id']));
        $lead->save();

        if (! empty($fields['tags'])) {
            $lead->tags()->sync($fields['tags']);
        }

        $activity = $lead->activities()->make([
            'type' => ActivityType::System, 'description' => "Imported from “{$import->original_filename}”",
            'occurred_at' => now(), 'meta' => ['event' => 'imported', 'import_id' => $import->id],
        ]);
        $activity->user_id = $importer->id;
        $activity->save();

        if (! empty($fields['assigned_to'])) {
            $this->assigner->assign($lead, User::find($fields['assigned_to']), $importer, 'import');
        }
    }

    /** Update: only fields that have a value in the file; status/assignee through the services. */
    private function updateLead(Lead $lead, array $fields, User $importer): void
    {
        $plain = collect($fields)->only(array_diff($lead->getFillable(), ['deal_value', 'currency', 'lead_source_id']))
            ->filter(fn ($v) => $v !== null && $v !== '')->all();
        $lead->fill($plain);
        if (isset($fields['deal_value'])) {
            $lead->deal_value = $fields['deal_value'];
            $lead->currency = $fields['currency'];
        }
        if (! empty($fields['source_explicit'])) {
            $lead->lead_source_id = $fields['source_id'];
        }
        $changed = array_keys($lead->getDirty());
        $lead->save();

        if (! empty($fields['tags'])) {
            $lead->tags()->syncWithoutDetaching($fields['tags']);
        }
        if (! empty($fields['status_explicit'])) {
            $this->statuses->change($lead, LeadStatus::find($fields['status_id']), $importer, 'import');
        }
        if (array_key_exists('assigned_to', $fields) && $fields['assigned_to'] !== null && $fields['assigned_to'] !== $lead->assigned_to) {
            $to = User::find($fields['assigned_to']);
            if ($to && $importer->can('assign', [$lead, $to])) {
                $this->assigner->assign($lead, $to, $importer, 'import');
            }
        }
        if ($changed !== []) {
            Audit::log('lead.updated', $lead, ['fields' => $changed, 'via' => 'import'], $importer->id);
        }
    }

    // ---- 4. Undo -----------------------------------------------------------------------------

    /**
     * Archive the leads this import created, unless they were edited since; updates to existing
     * leads are not reverted. @return array{archived: int, kept: list<string>}
     */
    public function undo(Import $import, User $by): array
    {
        return DB::transaction(function () use ($import, $by) {
            $archived = 0;
            $kept = [];
            $import->leads()->whereNull('deleted_at')->orderBy('id')->chunkById(500, function ($leads) use ($import, &$archived, &$kept) {
                foreach ($leads as $lead) {
                    if ($lead->updated_at->gt($import->completed_at)) {
                        $kept[] = $lead->reference;

                        continue;
                    }
                    $lead->delete();
                    $archived++;
                }
            });

            $import->forceFill(['undone_at' => now(), 'status' => 'undone'])->save();
            Audit::log('import.undone', $import, ['archived' => $archived, 'kept' => count($kept)], $by->id);

            return ['archived' => $archived, 'kept' => $kept];
        });
    }

    // ---- Row mapping -------------------------------------------------------------------------

    /** @return array{statuses: Collection, sources: Collection, tags: Collection, users: Collection} */
    private function lookups(): array
    {
        return [
            'statuses' => LeadStatus::query()->get(),
            'sources' => LeadSource::query()->get(),
            'tags' => Tag::query()->get(),
            'users' => User::query()->where('is_active', true)->get(['id', 'name', 'email', 'role', 'is_active']),
        ];
    }

    /**
     * One file row → lead fields + plain-language errors. Errors are stored unencrypted
     * (import_rows.errors), so they never quote contact details (email, phone); the original
     * values stay in the encrypted row data and the error-rows CSV.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function mapRow(array $data, array $mapping, array $options, array &$lookups, User $importer): array
    {
        $v = [];
        foreach ($mapping as $header => $field) {
            if ($field !== 'none' && array_key_exists($field, self::FIELDS)) {
                $value = trim((string) ($data[$header] ?? ''));
                $v[$field] = isset($v[$field]) && $v[$field] !== '' ? $v[$field] : $value;
            }
        }

        $errors = [];
        $f = [];

        if (! empty($v['full_name']) && empty($v['first_name']) && empty($v['last_name'])) {
            $parts = preg_split('/\s+/', $v['full_name'], 2);
            $v['first_name'] = $parts[0];
            $v['last_name'] = $parts[1] ?? '';
        }
        foreach (['first_name' => 100, 'last_name' => 100, 'company' => 191, 'job_title' => 150, 'address_line1' => 191, 'city' => 100,
            'state' => 100, 'postal_code' => 20, 'country' => 100, 'description' => 5000, 'secondary_phone' => 50] as $field => $max) {
            if (($v[$field] ?? '') !== '') {
                $f[$field] = mb_substr($v[$field], 0, $max);
            }
        }

        if (empty($f['first_name']) && empty($f['company'])) {
            $errors[] = 'Missing a first name or company';
        }
        $email = $v['email'] ?? '';
        $phone = $v['phone'] ?? '';
        if ($email === '' && $phone === '') {
            $errors[] = 'Missing an email or phone';
        }
        if ($email !== '') {
            filter_var($email, FILTER_VALIDATE_EMAIL) ? $f['email'] = mb_strtolower($email) : $errors[] = "Email isn't a valid email address";
        }
        if (($v['secondary_email'] ?? '') !== '') {
            filter_var($v['secondary_email'], FILTER_VALIDATE_EMAIL) ? $f['secondary_email'] = $v['secondary_email'] : $errors[] = 'Secondary email isn\'t valid';
        }
        if ($phone !== '') {
            strlen(preg_replace('/\D+/', '', $phone)) >= 6 ? $f['phone'] = mb_substr($phone, 0, 50) : $errors[] = 'Phone number needs at least 6 digits';
        }
        if (($v['website'] ?? '') !== '') {
            $site = preg_match('#^https?://#i', $v['website']) ? $v['website'] : 'https://'.$v['website'];
            filter_var($site, FILTER_VALIDATE_URL) ? $f['website'] = mb_substr($site, 0, 255) : $errors[] = 'Website isn\'t a valid web address';
        }
        if (($v['priority'] ?? '') !== '') {
            ($p = LeadPriority::tryFrom(mb_strtolower($v['priority']))) ? $f['priority'] = $p->value : $errors[] = "Unknown priority “{$v['priority']}” (use Low, Medium, High or Urgent)";
        }

        // Currency (falls back to the default) and deal value.
        $currency = Currency::default();
        if (($v['currency'] ?? '') !== '') {
            $c = Currency::tryFrom(mb_strtoupper($v['currency']));
            $c && in_array($c, Currency::enabled(), true) ? $currency = $c : $errors[] = "Currency “{$v['currency']}” isn't enabled";
        }
        $f['currency'] = $currency->value;
        if (($v['deal_value'] ?? '') !== '') {
            $money = Money::parse($v['deal_value'], $currency);
            $money === false ? $errors[] = "Deal value “{$v['deal_value']}” isn't a number" : $f['deal_value'] = $money;
        }

        // Status / source / tags / assignee, with the chosen handling for unknown values.
        $defaultStatus = (int) Settings::get('default_status_id');
        $f['status_id'] = $defaultStatus;
        if (($v['status'] ?? '') !== '') {
            // Only active statuses/sources match; a turned-off one is treated as unknown (like the form).
            $status = $lookups['statuses']->first(fn ($s) => $s->is_active && mb_strtolower($s->name) === mb_strtolower($v['status']));
            if (! $status) {
                $status = $this->unknown('status', $v['status'], $options, $importer, $lookups, $errors);
            }
            if ($status) {
                $f['status_id'] = $status->id;
                $f['status_explicit'] = true;
            }
        }
        $f['source_id'] = (int) Settings::get('default_source_id');
        if (($v['source'] ?? '') !== '') {
            $source = $lookups['sources']->first(fn ($s) => $s->is_active && mb_strtolower($s->name) === mb_strtolower($v['source']));
            if (! $source) {
                $source = $this->unknown('source', $v['source'], $options, $importer, $lookups, $errors);
            }
            if ($source) {
                $f['source_id'] = $source->id;
                $f['source_explicit'] = true;
            }
        }
        if (($v['tags'] ?? '') !== '') {
            $f['tags'] = [];
            foreach (array_filter(array_map('trim', explode(',', $v['tags']))) as $name) {
                $tag = $lookups['tags']->first(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name))
                    ?? $this->unknown('tags', $name, $options, $importer, $lookups, $errors);
                if ($tag) {
                    $f['tags'][] = $tag->id;
                }
            }
        }
        if (($v['assigned_to'] ?? '') !== '') {
            $needle = mb_strtolower($v['assigned_to']);
            $user = $lookups['users']->first(fn ($u) => mb_strtolower($u->email) === $needle || mb_strtolower($u->name) === $needle)
                ?? $this->unknown('assigned_to', $v['assigned_to'], $options, $importer, $lookups, $errors);
            if ($user && ! Permissions::allows($importer, 'assign-leads') && $user->id !== $importer->id) {
                $user = null; // can't assign to others: leave as imported default
            }
            $f['assigned_to'] = $user?->id;
        }

        return [$f, array_values(array_unique($errors))];
    }

    /**
     * Unknown status / source / tag / user. Choices: default (use the default / leave out), create
     * (admin only; users are never created), skip (the row becomes an error and isn't imported).
     */
    private function unknown(string $kind, string $value, array $options, User $importer, array &$lookups, array &$errors): mixed
    {
        $choice = $options['unknown'][$kind] ?? 'default';
        $label = ['status' => 'status', 'source' => 'source', 'tags' => 'tag', 'assigned_to' => 'user'][$kind];

        if ($choice === 'skip') {
            $errors[] = "Unknown {$label} “{$value}”";

            return null;
        }
        if ($choice === 'create' && $kind !== 'assigned_to' && $importer->isAdmin()) {
            $name = mb_substr($value, 0, 100);
            $key = ['status' => 'statuses', 'source' => 'sources', 'tags' => 'tags'][$kind];
            // A turned-off status/source with this name exists: never revive or duplicate it; use the default.
            if ($lookups[$key]->contains(fn ($m) => mb_strtolower($m->name) === mb_strtolower($name))) {
                return null;
            }
            $model = match ($kind) {
                'status' => LeadStatus::firstOrCreate(['name' => $name], ['color' => config('crm.palette.slate.hex'), 'type' => LeadStatusType::Open, 'sort_order' => (int) LeadStatus::max('sort_order') + 10, 'is_active' => true]),
                'source' => LeadSource::firstOrCreate(['name' => $name], ['is_active' => true, 'sort_order' => (int) LeadSource::max('sort_order') + 10]),
                'tags' => Tag::firstOrCreate(['name' => $name], ['color' => config('crm.palette.slate.hex')]),
            };
            if ($model->wasRecentlyCreated) {
                Audit::log(($kind === 'tags' ? 'tag' : $kind).'.created', $model, ['name' => $name, 'via' => 'import'], $importer->id);
            }
            $lookups[$key]->push($model);

            return $model;
        }

        return null; // default: status/source fall back to the defaults, tags/users are left out
    }
}
