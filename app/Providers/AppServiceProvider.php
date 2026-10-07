<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
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

    /**
     * One gate per `gate` entry in config('crm.permissions') (e.g. manage-users, view-reports).
     * The same list drives the policies and the Settings permissions table.
     */
    private function defineGates(): void
    {
        foreach (Permissions::gates() as $ability) {
            Gate::define($ability, fn (User $user) => Permissions::allows($user, $ability));
        }
    }
}
