<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->guardProductionConfig();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        Password::defaults(fn () => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->uncompromised());

        $this->defineGates();
    }

    /**
     * Refuse to boot a production app with debug mode on (it would leak stack traces and env values).
     */
    private function guardProductionConfig(): void
    {
        if ($this->app->isProduction() && config('app.debug')) {
            throw new RuntimeException('Refusing to boot: APP_DEBUG must be false when APP_ENV=production.');
        }
    }

    private function defineGates(): void
    {
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('manage-settings', fn (User $user) => $user->isAdmin());
        Gate::define('view-audit-log', fn (User $user) => $user->isAdmin());
        // Managers can see the user list (e.g. for assignment and team overview) but cannot change users.
        Gate::define('view-users', fn (User $user) => $user->hasAnyRole(Role::Admin, Role::Manager));
        Gate::define('export-leads', fn (User $user) => $user->hasAnyRole(Role::Admin, Role::Manager));
        Gate::define('import-leads', fn (User $user) => $user->hasAnyRole(Role::Admin, Role::Manager));
    }
}
