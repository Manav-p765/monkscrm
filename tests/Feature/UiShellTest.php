<?php

namespace Tests\Feature;

use App\Enums\LeadPriority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_name_comes_from_config_on_login_and_sidebar(): void
    {
        config(['app.name' => 'Renamed CRM']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<title>Sign In · Renamed CRM</title>', false)
            ->assertSee('Renamed CRM');

        $this->actingAs(User::factory()->create())->get('/leads')
            ->assertOk()
            ->assertSee('<title>Lead Directory · Renamed CRM</title>', false);
    }

    public function test_default_product_name_is_monks_crm(): void
    {
        $this->assertStringContainsString('APP_NAME="Monks CRM"', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString("env('APP_NAME', 'Monks CRM')", file_get_contents(config_path('app.php')));
    }

    public function test_every_page_has_one_h1_title_and_no_inline_styles_or_submit_buttons(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/dashboard', '/leads', '/follow-ups', '/imports', '/reports', '/users', '/settings', '/audit-log', '/profile'] as $uri) {
            $html = $this->actingAs($admin)->get($uri)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$uri} should have exactly one page title.");
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html, "{$uri} must not use style attributes (CSP).");
            $this->assertDoesNotMatchRegularExpression('/>\s*(Submit|OK)\s*</', $html, "{$uri} has a vague button label.");
        }
    }

    public function test_success_flash_is_shown_and_auto_dismisses(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Lead saved'])
            ->get('/dashboard')
            ->assertSee('Lead saved')
            ->assertSee('data-auto-dismiss="true"', false);
    }

    public function test_badge_uses_database_color_via_nonce_style_and_rejects_bad_values(): void
    {
        $html = (string) $this->blade('<x-badge color="#2563EB">New</x-badge><x-badge color="#2563eb">Again</x-badge>');

        $this->assertSame(1, substr_count($html, '<style'), 'Each colour should be emitted once.');
        $this->assertStringContainsString('.badge-2563eb{--badge-color:#2563eb}', $html);
        $this->assertStringNotContainsString('style="', $html);

        $bad = (string) $this->blade('<x-badge color="red;}body{display:none">Bad</x-badge>');
        $this->assertStringNotContainsString('<style', $bad);
        $this->assertStringNotContainsString('display:none', $bad);
        $this->assertStringContainsString('bg-slate-100', $bad);
    }

    public function test_priority_colours_only_high_and_urgent(): void
    {
        $this->assertSame('neutral', LeadPriority::Low->tone());
        $this->assertSame('neutral', LeadPriority::Medium->tone());
        $this->assertSame('amber', LeadPriority::High->tone());
        $this->assertSame('red', LeadPriority::Urgent->tone());
    }

    public function test_form_field_shows_label_required_marker_and_error_next_to_field(): void
    {
        $html = (string) $this->withViewErrors(['phone' => 'Enter a phone number, e.g. +91 98765 43210.'])
            ->blade('<x-input name="phone" label="Phone number" required />');

        $this->assertStringContainsString('<label for="phone"', $html);
        $this->assertStringContainsString('Phone number', $html);
        $this->assertStringContainsString('<span aria-hidden="true"> *</span><span class="sr-only"> (required)</span>', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="phone-error"', $html);
        $this->assertStringContainsString('Enter a phone number, e.g. +91 98765 43210.', $html);
    }

    public function test_submit_buttons_have_loading_text(): void
    {
        $this->get('/login')->assertSee('data-loading-text="Signing in…"', false);
    }

    public function test_confirm_dialog_names_the_item(): void
    {
        $html = (string) $this->blade(
            '<x-confirm-dialog trigger="Archive lead" action="/leads/1" method="DELETE" title="Archive LD-000001 (Jane Doe)?" confirm="Archive lead">Hidden from lists.</x-confirm-dialog>',
        );

        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('Archive LD-000001 (Jane Doe)?', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('>Cancel<', $html);
    }

    public function test_empty_state_and_table_render(): void
    {
        $html = (string) $this->blade(
            '<x-empty-state title="No leads yet">Add your first lead or import a CSV.<x-slot:actions><x-button href="/leads/create">Add lead</x-button></x-slot:actions></x-empty-state>'
            .'<x-table><x-slot:head><tr><th>Name</th></tr></x-slot:head><tr data-href="/leads/1"><td><a href="/leads/1">Jane</a></td></tr></x-table>',
        );

        $this->assertStringContainsString('No leads yet', $html);
        $this->assertStringContainsString('href="/leads/create"', $html);
        $this->assertStringContainsString('x-data="clickableRows"', $html);
        $this->assertStringContainsString('data-href="/leads/1"', $html);
    }
}
