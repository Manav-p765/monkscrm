<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportStagingTest extends TestCase
{
    use RefreshDatabase;

    private function import(): Import
    {
        $import = new Import(['original_filename' => 'leads.csv', 'status' => 'pending']);
        $import->user_id = User::factory()->create()->id;
        $import->save();

        return $import;
    }

    public function test_staged_row_data_is_encrypted_at_rest(): void
    {
        $import = $this->import();
        $row = $import->rows()->create(['row_number' => 1, 'data' => ['email' => 'secret.person@example.com', 'phone' => '+91 98765 43210']]);

        $raw = DB::table('import_rows')->where('id', $row->id)->value('data');
        $this->assertStringNotContainsString('secret.person', $raw);
        $this->assertStringNotContainsString('98765', $raw);
        $this->assertSame('secret.person@example.com', $row->fresh()->data['email']);
    }

    public function test_rows_are_deleted_when_the_import_completes_or_is_cancelled(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $import = $this->import();
            $import->rows()->create(['row_number' => 1, 'data' => ['x' => 1]]);

            $import->update(['status' => 'importing']);
            $this->assertSame(1, $import->rows()->count());

            $import->update(['status' => $status]);
            $this->assertSame(0, $import->rows()->count(), $status);
        }
    }

    public function test_starting_a_new_import_purges_rows_older_than_24_hours(): void
    {
        $old = $this->import();
        $old->rows()->create(['row_number' => 1, 'data' => ['x' => 1]]);
        ImportRow::query()->update(['created_at' => now()->subHours(25)]);

        $recent = $this->import(); // creating an import purges stale rows
        $recent->rows()->create(['row_number' => 1, 'data' => ['x' => 2]]);

        Carbon::setTestNow(now()->addHour());
        $this->import();
        Carbon::setTestNow();

        $this->assertSame(0, $old->rows()->count());
        $this->assertSame(1, $recent->rows()->count());
    }
}
