<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;
use ZipArchive;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->admin()->create(['email' => 'boss@example.com', 'name' => 'Boss Admin']);
        $this->manager = User::factory()->manager()->create();
        $this->sales = User::factory()->sales()->create(['email' => 'sam@example.com', 'name' => 'Sam Sales']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function csv(array $rows, string $name = 'leads.csv'): UploadedFile
    {
        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        rewind($fh);

        return UploadedFile::fake()->createWithContent($name, stream_get_contents($fh));
    }

    private function xlsx(array $rows, bool $macro = false, string $name = 'leads.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crm').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        if ($macro) {
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString('xl/vbaProject.bin', 'fake macro');
            $zip->close();
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    private const HEADER = ['Full Name', 'Company', 'Email', 'Phone', 'Lead Status', 'Source', 'Priority', 'Assigned To', 'Tags', 'Deal Value', 'Currency'];

    /** Upload → map (as guessed) → validate; returns the import. */
    private function upload(UploadedFile $file, ?User $as = null, array $unknown = []): Import
    {
        $this->actingAs($as ?? $this->admin)->post('/imports', ['file' => $file])->assertSessionHasNoErrors();
        $import = Import::latest('id')->first();
        $mapping = array_values($import->column_mapping);
        $this->post("/imports/{$import->id}/mapping", ['mapping' => $mapping, 'unknown' => $unknown])->assertSessionHasNoErrors();

        return $import->fresh();
    }

    private function runAll(Import $import): void
    {
        for ($i = 0; $i < 50; $i++) {
            $text = $this->withHeader('X-Requested-With', 'fetch')->post("/imports/{$import->id}/run")->assertOk()->getContent();
            if ($text === 'done') {
                $this->flushHeaders();

                return;
            }
        }
        $this->fail('Import never finished');
    }

    // ---- Permissions & upload checks ---------------------------------------------------------

    public function test_only_admins_and_managers_import_and_only_their_own_imports(): void
    {
        $this->actingAs($this->sales)->get('/imports')->assertForbidden();
        $this->actingAs($this->sales)->post('/imports', ['file' => $this->csv([self::HEADER])])->assertForbidden();

        $import = $this->upload($this->csv([self::HEADER, ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', '']]), $this->manager);
        $other = User::factory()->manager()->create();
        $this->actingAs($other)->get("/imports/{$import->id}")->assertNotFound();
        $this->actingAs($other)->post("/imports/{$import->id}/run")->assertNotFound();
        $this->actingAs($this->admin)->get("/imports/{$import->id}")->assertOk();
    }

    public function test_upload_accepts_csv_and_xlsx_and_rejects_macros_fakes_and_oversize(): void
    {
        $this->actingAs($this->admin);
        $row = ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', ''];

        $this->post('/imports', ['file' => $this->xlsx([self::HEADER, $row])])->assertSessionHasNoErrors();
        $this->assertSame(1, Import::latest('id')->first()->total_rows);

        $this->post('/imports', ['file' => $this->xlsx([self::HEADER, $row], true)])->assertSessionHasErrors('file');
        $this->post('/imports', ['file' => $this->xlsx([self::HEADER, $row], false, 'leads.xlsm')])->assertSessionHasErrors('file');
        $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('leads.xlsx', 'just text, not a workbook')])->assertSessionHasErrors('file');
        $this->post('/imports', ['file' => UploadedFile::fake()->create('leads.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');

        Settings::update(['import_max_file_mb' => 1]);
        $this->post('/imports', ['file' => UploadedFile::fake()->create('big.csv', 2048, 'text/csv')])->assertSessionHasErrors('file');

        Settings::update(['import_max_rows' => 100]);
        $rows = array_merge([self::HEADER], array_fill(0, 101, $row));
        $this->post('/imports', ['file' => $this->csv($rows)])->assertSessionHasErrors('file');
    }

    public function test_uploaded_file_is_deleted_and_staged_rows_are_encrypted(): void
    {
        $file = $this->csv([self::HEADER, ['Ann Lee', 'Acme', 'secret.ann@x.com', '+91 98765 43210', '', '', '', '', '', '', '']]);
        $path = $file->getRealPath();
        $this->actingAs($this->admin)->post('/imports', ['file' => $file])->assertRedirect();

        $this->assertFileDoesNotExist($path);
        $raw = \DB::table('import_rows')->value('data');
        $this->assertStringNotContainsString('secret.ann', $raw);
    }

    // ---- Validation, duplicates, strategies ---------------------------------------------------

    public function test_full_flow_with_validation_duplicates_and_skip_strategy(): void
    {
        Lead::factory()->create(['email' => 'existing@x.com', 'phone' => null]);
        $import = $this->upload($this->csv([
            self::HEADER,
            ['Ann Lee', 'Acme', 'ann@x.com', '+91 98765 43210', 'Qualified', 'Referral', 'High', 'sam@example.com', 'Hot Lead, VIP', '2,50,000', 'INR'],
            ['', '', '', '', '', '', '', '', '', '', ''],                                     // empty: ignored
            ['Bad Email', 'Co', 'not-an-email', '', '', '', '', '', '', '', ''],              // error
            ['No Contact', 'Co', '', '', '', '', '', '', '', '', ''],                          // error
            ['Dup Of Ann', 'Co', 'ANN@x.com', '', '', '', '', '', '', '', ''],                 // duplicate within the file
            ['Existing', 'Co', 'existing@x.com', '', '', '', '', '', '', '', ''],              // duplicate in DB
            ['Odd Currency', 'Co', 'odd@x.com', '', '', '', '', '', '', '500', 'EUR'],        // currency not enabled
            ['Plain', 'Co', 'plain@x.com', '', 'Totally Unknown', '', '', '', '', '100', ''], // unknown status → default
        ]));

        $this->assertSame([7, 4, 3, 2], [$import->total_rows, $import->valid_rows, $import->invalid_rows, $import->duplicate_rows]);
        $this->get("/imports/{$import->id}?step=validate")->assertOk()->assertSee('isn&#039;t a valid email', false);

        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'skip'])->assertRedirect();
        $this->runAll($import);
        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame([2, 0, 2], [$import->imported_rows, $import->updated_rows, $import->skipped_rows]);
        $this->assertSame(0, ImportRow::count()); // staged rows deleted on completion

        $ann = Lead::where('email', 'ann@x.com')->first();
        $this->assertSame($import->id, $ann->import_id);
        $this->assertSame('Ann', $ann->first_name);
        $this->assertSame(LeadStatus::where('name', 'Qualified')->value('id'), $ann->lead_status_id);
        $this->assertNotNull($ann->qualified_at);
        $this->assertSame(25000000, $ann->deal_value);
        $this->assertSame('INR', $ann->currency->value);
        $this->assertSame($this->sales->id, $ann->assigned_to);
        $this->assertSame(['Hot Lead', 'VIP'], $ann->tags->pluck('name')->sort()->values()->all());

        $plain = Lead::where('email', 'plain@x.com')->first();
        $this->assertSame((int) Settings::get('default_status_id'), $plain->lead_status_id);
        $this->assertSame((string) Settings::get('default_currency'), $plain->currency->value);

        // Error rows CSV keeps the original values and the reason.
        $errors = $this->get("/imports/{$import->id}/errors.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('not-an-email', $errors);
        $this->assertStringContainsString('isn\'t enabled', $errors);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.completed']);
    }

    public function test_update_and_new_strategies(): void
    {
        $existing = Lead::factory()->create(['email' => 'existing@x.com', 'phone' => null, 'company' => 'Old Co', 'city' => 'Pune', 'lead_status_id' => LeadStatus::where('name', 'New')->value('id')]);
        $rows = [self::HEADER, ['Ex Isting', 'New Co', 'existing@x.com', '', 'Won', '', '', '', '', '', '']];

        $import = $this->upload($this->csv($rows));
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'update'])->assertRedirect();
        $this->runAll($import);
        $existing->refresh();
        $this->assertSame('New Co', $existing->company);
        $this->assertSame('Pune', $existing->city); // empty cells don't wipe data
        $this->assertNotNull($existing->won_at);   // status changed through LeadStatusChanger
        $this->assertSame(1, $import->fresh()->updated_rows);
        $this->assertSame('import', AuditLog::where('action', 'lead.status_changed')->latest('id')->first()->meta['via']);

        $import2 = $this->upload($this->csv($rows));
        $this->post("/imports/{$import2->id}/strategy", ['strategy' => 'new'])->assertRedirect();
        $this->runAll($import2);
        $this->assertSame(2, Lead::where('email', 'existing@x.com')->count());
    }

    public function test_unknown_values_skip_or_create_admin_only(): void
    {
        $rows = [self::HEADER, ['Ann Lee', 'Acme', 'ann@x.com', '', 'Brand New Stage', 'Podcast', '', '', 'Shiny', '', '']];

        $skip = $this->upload($this->csv($rows), $this->admin, ['status' => 'skip']);
        $this->assertSame(1, $skip->invalid_rows);

        $create = $this->upload($this->csv($rows), $this->admin, ['status' => 'create', 'source' => 'create', 'tags' => 'create']);
        $this->assertSame(0, $create->invalid_rows);
        $this->post("/imports/{$create->id}/strategy", ['strategy' => 'skip']);
        $this->runAll($create);
        $this->assertTrue(LeadStatus::where('name', 'Brand New Stage')->exists());
        $this->assertTrue(LeadSource::where('name', 'Podcast')->exists());
        $this->assertTrue(Tag::where('name', 'Shiny')->exists());

        // Managers can't choose "create".
        $this->actingAs($this->manager)->post('/imports', ['file' => $this->csv($rows)]);
        $m = Import::latest('id')->first();
        $this->post("/imports/{$m->id}/mapping", ['mapping' => array_values($m->column_mapping), 'unknown' => ['status' => 'create']])->assertSessionHasErrors('unknown.status');
    }

    public function test_chunks_resume_until_done(): void
    {
        $rows = [self::HEADER];
        for ($i = 1; $i <= 1100; $i++) {
            $rows[] = ["Lead {$i}", 'Co', "lead{$i}@x.com", '', '', '', '', '', '', '', ''];
        }
        $import = $this->upload($this->csv($rows));
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'skip']);

        $first = $this->withHeader('X-Requested-With', 'fetch')->post("/imports/{$import->id}/run")->getContent();
        $this->assertSame('500/1100', $first);
        $this->assertSame('importing', $import->fresh()->status);
        $this->flushHeaders();

        // A page reload shows the importing step and picks up where it stopped.
        $this->get("/imports/{$import->id}")->assertOk()->assertSee('Continue Import');
        $this->runAll($import);
        $this->assertSame(1100, $import->fresh()->imported_rows);
        $this->assertSame(1100, Lead::count());
    }

    public function test_cancel_and_stale_rows_are_cleaned_up(): void
    {
        $import = $this->upload($this->csv([self::HEADER, ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', '']]));
        $this->assertSame(1, ImportRow::count());
        $this->post("/imports/{$import->id}/cancel")->assertRedirect();
        $this->assertSame(0, ImportRow::count());
        $this->assertSame(0, Lead::count());
    }

    // ---- Undo -----------------------------------------------------------------------------------

    public function test_undo_archives_untouched_imported_leads_within_24_hours(): void
    {
        $import = $this->upload($this->csv([self::HEADER,
            ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', ''],
            ['Bob Roy', 'Acme', 'bob@x.com', '', '', '', '', '', '', '', ''],
        ]));
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'skip']);
        $this->runAll($import);

        Carbon::setTestNow(now()->addHour());
        $bob = Lead::where('email', 'bob@x.com')->first();
        $bob->update(['city' => 'Pune']); // edited since the import

        $this->actingAs($this->manager)->post("/imports/{$import->id}/undo")->assertNotFound(); // not their import
        $this->actingAs($this->admin)->post("/imports/{$import->id}/undo")->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 imported lead archived') && str_contains($m, $bob->reference));

        $this->assertTrue(Lead::withTrashed()->where('email', 'ann@x.com')->first()->trashed());
        $this->assertFalse($bob->fresh()->trashed());
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.undone']);
        $this->post("/imports/{$import->id}/undo")->assertSessionHas('error'); // only once
    }

    public function test_wizard_labels_counts_and_template_name_render_correctly(): void
    {
        // Found in the browser test: the validation card showed "Ready &amp; Valid" (escaped twice).
        $import = $this->upload($this->csv([self::HEADER, ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', '']]));
        $this->get("/imports/{$import->id}?step=validate")->assertOk()
            ->assertSee('Ready &amp; Valid', false)
            ->assertDontSee('&amp;amp;', false);

        // The template is named after the product (APP_NAME), never a hard-coded name.
        config(['app.name' => 'Hybrid Test']);
        $this->get('/imports/template.csv')->assertOk()
            ->assertDownload('hybrid-test-import-template.csv');
    }

    public function test_undo_message_groups_thousands(): void
    {
        $rows = [self::HEADER];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = ["Bulk {$i}", 'Acme', "bulk{$i}@x.com", '', '', '', '', '', '', '', ''];
        }
        $import = $this->upload($this->csv($rows));
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'skip']);
        $this->runAll($import);

        $this->post("/imports/{$import->id}/undo")->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, '1,001 imported leads archived'));
    }

    public function test_undo_is_not_possible_after_24_hours(): void
    {
        $import = $this->upload($this->csv([self::HEADER, ['Ann Lee', 'Acme', 'ann@x.com', '', '', '', '', '', '', '', '']]));
        $this->post("/imports/{$import->id}/strategy", ['strategy' => 'skip']);
        $this->runAll($import);

        Carbon::setTestNow(now()->addHours(25));
        $this->post("/imports/{$import->id}/undo")->assertSessionHas('error');
        $this->assertFalse(Lead::where('email', 'ann@x.com')->first()->trashed());
    }

    // ---- Export ---------------------------------------------------------------------------------

    public function test_export_is_scoped_escaped_audited_and_rate_limited(): void
    {
        Lead::factory()->create(['first_name' => '=cmd|calc', 'email' => 'a@x.com', 'currency' => 'INR', 'deal_value' => 10000000, 'city' => 'Pune']);
        Lead::factory()->create(['first_name' => 'Other', 'email' => 'b@x.com', 'city' => 'Delhi']);

        $this->actingAs($this->sales)->get('/leads-export')->assertForbidden();

        $csv = $this->actingAs($this->manager)->get('/leads-export?city=pune&columns[]=first_name&columns[]=email&columns[]=deal_value&columns[]=currency')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('100000', $csv);
        $this->assertStringNotContainsString('b@x.com', $csv);
        $this->assertStringNotContainsString('Status', $csv); // only the chosen columns

        $log = AuditLog::where('action', 'leads.exported')->first();
        $this->assertSame(1, $log->meta['rows']);
        $this->assertSame('pune', $log->meta['filters']['city']);
        $this->assertStringNotContainsString('a@x.com', json_encode($log->meta));

        for ($i = 0; $i < 9; $i++) {
            $this->get('/leads-export')->assertOk();
        }
        $this->get('/leads-export')->assertStatus(429);
    }

    public function test_excel_export_is_scoped_text_only_and_audited(): void
    {
        Lead::factory()->create(['first_name' => '=cmd|calc', 'email' => 'a@x.com', 'currency' => 'INR', 'deal_value' => 10000050, 'city' => 'Pune']);
        Lead::factory()->create(['first_name' => 'Other', 'email' => 'b@x.com', 'city' => 'Delhi']);

        $this->actingAs($this->sales)->get('/leads-export?format=xlsx')->assertForbidden();

        $response = $this->actingAs($this->manager)
            ->get('/leads-export?format=xlsx&city=pune&columns[]=first_name&columns[]=email&columns[]=deal_value&columns[]=currency')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringEndsWith('.xlsx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringNotContainsString('<f>', $sheet, 'No cell is a formula');
        $this->assertStringContainsString('=cmd|calc', $sheet); // kept as plain text, no apostrophe
        $this->assertStringContainsString('<v>100000.5</v>', $sheet); // deal value is a number
        $this->assertStringContainsString('a@x.com', $sheet);
        $this->assertStringNotContainsString('b@x.com', $sheet);
        $this->assertStringNotContainsString('Status', $sheet);

        $log = AuditLog::where('action', 'leads.exported')->sole();
        $this->assertSame(['xlsx', 1], [$log->meta['format'], $log->meta['rows']]);
        $this->assertArrayNotHasKey('format', $log->meta['filters']);

        $this->get('/leads-export?format=pdf')->assertSessionHasErrors(['format' => 'Choose CSV or Excel for the export.']);
    }
}
