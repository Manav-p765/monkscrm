<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_only_admins_reach_settings(): void
    {
        $pages = ['/settings', '/settings/sources', '/settings/tags', '/settings/general', '/settings/permissions'];

        foreach ([User::factory()->manager()->create(), User::factory()->sales()->create()] as $user) {
            foreach ($pages as $page) {
                $this->actingAs($user)->get($page)->assertForbidden();
            }
            $this->actingAs($user)->post('/settings/tags', ['name' => 'X', 'color' => 'blue'])->assertForbidden();
            $this->actingAs($user)->put('/settings/general', [])->assertForbidden();
        }

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    // ---- Statuses ------------------------------------------------------------------------------

    public function test_add_and_edit_status_with_palette_colour_and_audit(): void
    {
        $this->actingAs($this->admin)->post('/settings/statuses', [
            'name' => 'Contract Review', 'color' => 'purple', 'type' => 'open', 'milestone' => '', 'description' => 'Legal is reviewing',
        ])->assertRedirect(route('settings.statuses'));

        $status = LeadStatus::where('name', 'Contract Review')->first();
        $this->assertSame(config('crm.palette.purple.hex'), $status->color);
        $this->assertSame(LeadStatus::max('sort_order'), $status->sort_order);
        $this->assertDatabaseHas('audit_logs', ['action' => 'status.created', 'subject_id' => $status->id]);

        $this->put("/settings/statuses/{$status->id}", ['name' => 'Contract Review', 'color' => 'rose', 'type' => 'open', 'is_active' => '1'])->assertRedirect();
        $changes = AuditLog::where('action', 'status.updated')->first()->meta['changes'];
        $this->assertSame(config('crm.palette.rose.hex'), $changes['color']['to']);

        // Only palette colours are accepted.
        $this->put("/settings/statuses/{$status->id}", ['name' => 'Contract Review', 'color' => '#123456', 'type' => 'open'])
            ->assertSessionHasErrorsIn('status'.$status->id, 'color');
    }

    public function test_default_status_must_stay_active_and_open(): void
    {
        $new = LeadStatus::where('name', 'New')->first();
        $this->assertSame($new->id, Settings::get('default_status_id'));

        $this->actingAs($this->admin)->put("/settings/statuses/{$new->id}", ['name' => 'New', 'color' => 'blue', 'type' => 'open', 'is_active' => '0'])
            ->assertSessionHasErrorsIn('status'.$new->id, 'is_active');
        $this->put("/settings/statuses/{$new->id}", ['name' => 'New', 'color' => 'blue', 'type' => 'won', 'is_active' => '1'])
            ->assertSessionHasErrorsIn('status'.$new->id, 'is_active');
        $this->assertTrue($new->fresh()->is_active);

        // Make another status the default, then New can be disabled.
        $contacted = LeadStatus::where('name', 'Contacted')->first();
        $this->post("/settings/statuses/{$contacted->id}/default")->assertRedirect();
        $this->assertSame($contacted->id, Settings::get('default_status_id'));
        $this->put("/settings/statuses/{$new->id}", ['name' => 'New', 'color' => 'blue', 'type' => 'open', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($new->fresh()->is_active);

        // A won status can't become the default.
        $won = LeadStatus::where('type', 'won')->first();
        $this->post("/settings/statuses/{$won->id}/default")->assertSessionHas('error');
        $this->assertSame($contacted->id, Settings::get('default_status_id'));
    }

    public function test_last_active_open_status_cannot_be_disabled(): void
    {
        $open = LeadStatus::where('type', 'open')->ordered()->get();
        $keep = $open->first();
        Settings::update(['default_status_id' => $keep->id]);
        LeadStatus::whereKeyNot($keep->id)->where('type', 'open')->update(['is_active' => false]);

        // $keep is now the default AND the last active Open status.
        Settings::update(['default_status_id' => LeadStatus::where('type', 'won')->value('id')]); // simulate a bad default
        $this->actingAs($this->admin)->put("/settings/statuses/{$keep->id}", ['name' => $keep->name, 'color' => 'blue', 'type' => 'open', 'is_active' => '0'])
            ->assertSessionHasErrorsIn('status'.$keep->id, 'is_active');
        $this->assertTrue($keep->fresh()->is_active);
    }

    public function test_disabled_status_still_shows_on_existing_leads_but_not_in_pickers(): void
    {
        $status = LeadStatus::where('name', 'Opportunity')->first();
        $lead = Lead::factory()->create(['lead_status_id' => $status->id]);
        $status->update(['is_active' => false]);

        $this->assertSame('Opportunity', $lead->fresh()->status->name);
        $this->assertStringContainsString('Opportunity', Blade::render('<x-badge :color="$s->color">{{ $s->name }}</x-badge>', ['s' => $lead->fresh()->status]));
        $this->assertNotContains('Opportunity', LeadStatus::active()->pluck('name')->all());
    }

    public function test_statuses_and_sources_are_never_deleted(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'settings/statuses') || str_starts_with($r->uri(), 'settings/sources'));

        foreach ($routes as $route) {
            $this->assertNotContains('DELETE', $route->methods(), $route->uri());
        }
    }

    public function test_reorder_by_drag_and_by_buttons(): void
    {
        $ids = LeadStatus::ordered()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->admin)->post('/settings/statuses/reorder', ['ids' => $reversed])->assertRedirect();
        $this->assertSame($reversed, LeadStatus::ordered()->pluck('id')->all());

        // An incomplete list (someone else changed it meanwhile) is refused.
        $this->post('/settings/statuses/reorder', ['ids' => array_slice($ids, 1)])->assertSessionHas('error');

        $first = LeadStatus::ordered()->first();
        $this->post("/settings/statuses/{$first->id}/move", ['direction' => 'down'])->assertRedirect();
        $this->assertSame($first->id, LeadStatus::ordered()->pluck('id')->all()[1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'statuses.reordered']);
    }

    // ---- Sources & tags ------------------------------------------------------------------------

    public function test_default_source_cannot_be_turned_off(): void
    {
        $manual = LeadSource::where('name', 'Manual Entry')->first();

        $this->actingAs($this->admin)->put("/settings/sources/{$manual->id}", ['name' => 'Manual Entry', 'is_active' => '0'])
            ->assertSessionHasErrorsIn('source'.$manual->id, 'is_active');
        $this->assertTrue($manual->fresh()->is_active);

        $web = LeadSource::where('name', 'Website')->first();
        $this->post("/settings/sources/{$web->id}/default")->assertRedirect();
        $this->assertSame($web->id, Settings::get('default_source_id'));
        $this->put("/settings/sources/{$manual->id}", ['name' => 'Manual entry (desk)', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($manual->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'source.updated']);
    }

    public function test_names_are_unique_ignoring_case(): void
    {
        $this->actingAs($this->admin);

        $this->post('/settings/tags', ['name' => 'hot lead', 'color' => 'rose'])->assertSessionHasErrorsIn('tag', 'name');
        $this->post('/settings/statuses', ['name' => 'WON', 'color' => 'blue', 'type' => 'won'])->assertSessionHasErrorsIn('status', 'name');
        $this->post('/settings/sources', ['name' => 'referral', 'is_active' => '1'])->assertSessionHasErrorsIn('source', 'name');

        $this->assertSame(1, Tag::whereRaw('lower(name) = ?', ['hot lead'])->count());
        $this->assertSame(1, LeadStatus::whereRaw('lower(name) = ?', ['won'])->count());
        $this->assertSame(1, LeadSource::whereRaw('lower(name) = ?', ['referral'])->count());
    }

    public function test_deleting_a_tag_removes_its_links_and_says_how_many(): void
    {
        $tag = Tag::first();
        $leads = Lead::factory()->count(3)->create();
        $tag->leads()->attach($leads->pluck('id'));

        $this->actingAs($this->admin)->get('/settings/tags')->assertSee('3 leads will lose this tag');

        $this->delete("/settings/tags/{$tag->id}")->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'removed from 3 leads'));
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertSame(0, DB::table('lead_tag')->where('tag_id', $tag->id)->count());
        $this->assertSame(3, Lead::count());
        $this->assertSame(3, AuditLog::where('action', 'tag.deleted')->first()->meta['leads_untagged']);
    }

    // ---- General settings, cache, currencies ---------------------------------------------------

    private function general(array $overrides = []): array
    {
        return [
            'sales_can_view_all_leads' => '0', 'sales_can_view_unassigned' => '1', 'allow_duplicate_override' => '1',
            'enabled_currencies' => ['USD', 'INR'], 'default_currency' => 'USD',
            'import_max_file_mb' => 5, 'import_max_rows' => 5000, ...$overrides,
        ];
    }

    public function test_general_settings_save_audit_old_and_new_and_clear_the_cache(): void
    {
        $this->assertFalse(Settings::get('sales_can_view_all_leads'));
        $this->assertTrue(Cache::has(Settings::CACHE_KEY));

        $this->actingAs($this->admin)->put('/settings/general', $this->general([
            'sales_can_view_all_leads' => '1', 'enabled_currencies' => ['USD', 'INR', 'EUR'], 'default_currency' => 'EUR',
        ]))->assertRedirect(route('settings.general'));

        $this->assertFalse(Cache::has(Settings::CACHE_KEY)); // cleared on update
        $this->assertTrue(Settings::get('sales_can_view_all_leads'));
        $this->assertSame(['USD', 'INR', 'EUR'], Settings::get('enabled_currencies'));

        $entry = AuditLog::where('action', 'settings.updated')->get()->firstWhere('meta.setting', 'enabled_currencies');
        $this->assertSame(['setting' => 'enabled_currencies', 'from' => ['USD', 'INR'], 'to' => ['USD', 'INR', 'EUR']], $entry->meta);
        $this->assertSame('EUR', AuditLog::where('action', 'settings.updated')->get()->firstWhere('meta.setting', 'default_currency')->meta['to']);
        // Unchanged values aren't logged.
        $this->assertNull(AuditLog::where('action', 'settings.updated')->get()->firstWhere('meta.setting', 'import_max_rows'));
    }

    public function test_settings_cache_is_cleared_when_a_setting_row_changes(): void
    {
        Settings::get('import_max_rows');
        $this->assertTrue(Cache::has(Settings::CACHE_KEY));

        Setting::where('key', 'import_max_rows')->first()->update(['value' => 1234]);

        $this->assertFalse(Cache::has(Settings::CACHE_KEY));
        $this->assertSame(1234, Settings::get('import_max_rows'));
    }

    public function test_default_currency_cannot_be_disabled_and_limits_are_capped(): void
    {
        $this->actingAs($this->admin);

        $this->put('/settings/general', $this->general(['enabled_currencies' => ['INR'], 'default_currency' => 'USD']))
            ->assertSessionHasErrors('default_currency');
        $this->put('/settings/general', $this->general(['enabled_currencies' => []]))->assertSessionHasErrors('enabled_currencies');
        $this->put('/settings/general', $this->general(['enabled_currencies' => ['USD', 'XYZ']]))->assertSessionHasErrors('enabled_currencies.1');
        $this->put('/settings/general', $this->general(['import_max_file_mb' => 21, 'import_max_rows' => 20001]))
            ->assertSessionHasErrors(['import_max_file_mb', 'import_max_rows']);

        $this->assertSame(['USD', 'INR'], Settings::get('enabled_currencies'));
        $this->assertSame('USD', Settings::get('default_currency'));
    }

    // ---- Permissions table ---------------------------------------------------------------------

    public function test_permissions_table_matches_the_actual_gates(): void
    {
        $html = $this->actingAs($this->admin)->get('/settings/permissions')->assertOk()->getContent();

        $users = collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => User::factory()->create(['role' => $r])]);

        foreach (Permissions::all() as $permission) {
            foreach (Role::cases() as $role) {
                $shown = preg_match('#data-permission="'.$permission['key'].'".*?data-role="'.$role->value.'" data-allowed="(\w+)"#s', $html, $m) ? $m[1] : null;
                $this->assertNotNull($shown, "{$permission['key']} / {$role->value} missing");

                $expected = in_array($role->value, $permission['roles'], true);
                $this->assertSame($expected, $shown === 'yes', "{$permission['key']} / {$role->value}");

                if ($permission['gate'] ?? false) {
                    $this->assertSame($expected, Gate::forUser($users[$role->value])->allows($permission['key']), "gate {$permission['key']} / {$role->value}");
                }
            }
        }
    }
}
