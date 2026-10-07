<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The only routes a guest may reach.
     */
    private const PUBLIC_ROUTE_NAMES = [
        'login',
        'login.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'two-factor.login',
        'two-factor.login.store',
    ];

    public function test_every_non_public_route_requires_authentication(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->reject(fn (Route $route) => $route->isFallback)
            ->reject(fn (Route $route) => in_array($route->getName(), self::PUBLIC_ROUTE_NAMES, true));

        $this->assertGreaterThan(10, $routes->count());

        foreach ($routes as $route) {
            $hasAuth = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && ($m === 'auth' || Str::startsWith($m, 'auth:')));
            $this->assertTrue($hasAuth, "Route [{$route->uri()}] is missing auth middleware.");

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = preg_replace('/\{[^}]+\}/', 'x', $route->uri());

            $response = $this->call($method, '/'.ltrim($uri, '/'));

            $this->assertTrue(
                $response->isRedirect(route('login')),
                "Guest {$method} /{$uri} should redirect to login, got {$response->status()}."
            );
        }
    }

    public function test_unknown_route_returns_plain_404(): void
    {
        $this->get('/this-does-not-exist')->assertNotFound()->assertDontSee('Exception');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/this-does-not-exist')->assertNotFound();
    }

    public static function adminOnlyRoutes(): array
    {
        return [
            'settings' => ['/settings'],
            'audit log' => ['/audit-log'],
        ];
    }

    public static function adminOrManagerRoutes(): array
    {
        return [
            'users' => ['/users'],
            'imports' => ['/imports'],
            'reports' => ['/reports'],
        ];
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_sales_user_gets_403_on_admin_only_routes(string $uri): void
    {
        $sales = User::factory()->sales()->create();

        $this->actingAs($sales)->get($uri)->assertForbidden();
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_manager_gets_403_on_admin_only_routes(string $uri): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get($uri)->assertForbidden();
    }

    #[DataProvider('adminOrManagerRoutes')]
    public function test_sales_user_gets_403_on_admin_and_manager_routes(string $uri): void
    {
        $sales = User::factory()->sales()->create();

        $this->actingAs($sales)->get($uri)->assertForbidden();
    }

    #[DataProvider('adminOrManagerRoutes')]
    public function test_manager_can_access_manager_routes(string $uri): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get($uri)->assertOk();
    }

    public function test_admin_can_access_every_section(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/dashboard', '/leads', '/pipeline', '/follow-ups', '/imports', '/reports', '/users', '/settings', '/audit-log', '/profile'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }

    public function test_sales_user_can_access_general_sections(): void
    {
        $sales = User::factory()->sales()->create();

        foreach (['/dashboard', '/leads', '/pipeline', '/follow-ups', '/profile'] as $uri) {
            $this->actingAs($sales)->get($uri)->assertOk();
        }
    }

    public function test_sidebar_hides_links_the_role_cannot_use(): void
    {
        $sales = User::factory()->sales()->create(['name' => 'Sam Sales']);

        $this->actingAs($sales)->get('/dashboard')
            ->assertOk()
            ->assertSee('Good day, Sam')
            ->assertDontSee(route('users.index'))
            ->assertDontSee(route('settings.index'))
            ->assertDontSee(route('imports.index'))
            ->assertDontSee(route('reports.index'))
            ->assertDontSee(route('audit-log.index'));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard')
            ->assertSee(route('users.index'))
            ->assertSee(route('settings.index'))
            ->assertSee(route('audit-log.index'));
    }

    public function test_gates_match_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $sales = User::factory()->sales()->create();

        foreach (['manage-users', 'manage-teams', 'manage-settings', 'view-audit-log'] as $ability) {
            $this->assertTrue($admin->can($ability), $ability);
            $this->assertFalse($manager->can($ability), $ability);
            $this->assertFalse($sales->can($ability), $ability);
        }

        foreach (['export-leads', 'import-leads', 'view-users', 'view-reports'] as $ability) {
            $this->assertTrue($admin->can($ability), $ability);
            $this->assertTrue($manager->can($ability), $ability);
            $this->assertFalse($sales->can($ability), $ability);
        }
    }
}
