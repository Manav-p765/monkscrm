<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ValueError;

/**
 * Behaviour that must be identical on SQLite (default test run) and PostgreSQL (composer test:pgsql).
 */
class DatabasePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_is_case_insensitive_partial_match(): void
    {
        User::factory()->create(['name' => 'Priya Sharma', 'email' => 'priya@example.com']);
        User::factory()->create(['name' => 'Rahul Verma', 'email' => 'rahul@example.com']);

        $this->assertSame(['Priya Sharma'], User::query()->search('sHaR', ['name', 'email'])->pluck('name')->all());
        $this->assertSame(['Rahul Verma'], User::query()->search('RAHUL@EX', ['name', 'email'])->pluck('name')->all());
        $this->assertSame(2, User::query()->search('   ', ['name'])->count());
        $this->assertSame(0, User::query()->search('nobody', ['name', 'email'])->count());
    }

    public function test_search_treats_wildcards_literally_on_postgres(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite LIKE has no default escape character; checked on PostgreSQL.');
        }

        User::factory()->create(['name' => '100% Real']);
        User::factory()->create(['name' => '1000 Real']);

        $this->assertSame(['100% Real'], User::query()->search('0%', ['name'])->pluck('name')->all());
        $this->assertSame(0, User::query()->search('_', ['name'])->count());
    }

    public function test_emails_are_stored_lowercased_and_unique_ignoring_case(): void
    {
        $user = User::factory()->create(['email' => '  Priya@Example.COM ']);
        $this->assertSame('priya@example.com', $user->fresh()->email);

        // The database itself rejects a case variant, even if the model mutator is bypassed.
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'name' => 'Copy', 'email' => 'PRIYA@example.com', 'password' => 'x',
            'role' => 'sales', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_tag_names_are_unique_ignoring_case(): void
    {
        Tag::create(['name' => 'VIP', 'color' => '#7c3aed']);

        $this->expectException(QueryException::class);
        Tag::create(['name' => 'vip', 'color' => '#7c3aed']);
    }

    public function test_enum_like_columns_reject_unknown_values_in_the_model(): void
    {
        $this->expectException(ValueError::class);
        User::factory()->create(['role' => 'superadmin']);
    }

    public function test_json_columns_round_trip(): void
    {
        $user = User::factory()->create();
        $log = AuditLog::create(['user_id' => $user->id, 'action' => 'test.json', 'meta' => ['a' => 1, 'b' => ['c' => true]]]);

        $this->assertSame(['a' => 1, 'b' => ['c' => true]], $log->fresh()->meta);
    }
}
