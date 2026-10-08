<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The local demo set: complete enough to show every screen, and obviously fake.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_set_covers_every_screen_with_obviously_fake_data(): void
    {
        $this->app['env'] = 'local';
        try {
            $this->seed(DemoSeeder::class);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(['admin' => 1, 'manager' => 1, 'sales' => 3],
            User::query()->get()->countBy(fn (User $u) => $u->role->value)->sortKeys()->all());
        $this->assertSame(0, User::query()->where('email', 'not like', '%@example.com')->count());

        $this->assertSame(90, Lead::count()); // 80 + 10 from the import
        $this->assertSame(LeadStatus::count(), Lead::query()->distinct()->count('lead_status_id'));
        $this->assertEqualsCanonicalizing(['INR', 'USD'], Lead::query()->distinct()->pluck('currency')->map->value->all());
        $this->assertSame(0, Lead::query()->where('email', 'not like', '%@example.com')->count());
        $this->assertSame(0, Lead::query()->whereNotNull('phone')->where('phone', 'not like', '%555%')->count());

        $this->assertGreaterThan(20, \App\Models\LeadNote::count());
        $this->assertGreaterThan(100, \App\Models\Activity::where('meta->event', 'logged')->count());
        $this->assertGreaterThan(0, FollowUp::query()->where('status', 'pending')->where('due_at', '<', now())->count());
        $this->assertGreaterThan(0, FollowUp::query()->where('status', 'completed')->count());

        $import = Import::sole();
        $this->assertSame(['completed', 12, 10, 1], [$import->status, $import->total_rows, $import->imported_rows, $import->invalid_rows]);
        $this->assertSame(0, ImportRow::count());

        // Never re-run on top of existing data.
        $this->app['env'] = 'local';
        try {
            $this->expectException(RuntimeException::class);
            (new DemoSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
