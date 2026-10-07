<?php

namespace Tests\Feature;

use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DatesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stored_utc_is_displayed_in_ist(): void
    {
        $utc = Carbon::parse('2026-10-02 10:15:00', 'UTC');

        $this->assertSame('2 Oct 2026, 3:45 pm', Dates::format($utc));
        $this->assertSame('2 Oct 2026', Dates::day($utc));
        // 20:00 UTC is already the next day in IST.
        $this->assertSame('3 Oct 2026, 1:30 am', Dates::format(Carbon::parse('2026-10-02 20:00:00', 'UTC')));
        $this->assertSame('—', Dates::format(null));
    }

    public function test_relative_format_with_full_date_tooltip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 12:15:00', 'UTC'));
        $twoHoursAgo = Carbon::parse('2026-10-02 10:15:00', 'UTC');

        $this->assertSame('2 hours ago', Dates::relative($twoHoursAgo));
        $this->assertSame('just now', Dates::relative(now()->subSeconds(5)));

        $html = Blade::render('<x-date :value="$d" relative />', ['d' => $twoHoursAgo]);
        $this->assertStringContainsString('title="2 Oct 2026, 3:45 pm"', $html);
        $this->assertStringContainsString('datetime="2026-10-02T10:15:00+00:00"', $html);
        $this->assertStringContainsString('>2 hours ago</time>', $html);
    }

    public function test_user_input_is_read_as_ist_and_stored_as_utc(): void
    {
        $stored = Dates::fromInput('2026-10-02T15:45');

        $this->assertSame('UTC', $stored->timezoneName);
        $this->assertSame('2026-10-02 10:15:00', $stored->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 18:30:00', Dates::fromInput('2026-10-02')->format('Y-m-d H:i:s'));
        $this->assertNull(Dates::fromInput(''));
        // Round trip back into a form field.
        $this->assertSame('2026-10-02T15:45', Dates::toInput($stored));
    }
}
