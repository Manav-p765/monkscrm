<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use App\Support\ProductionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // As-you-type endpoints (header search, duplicate check): 60 requests a minute per user.
        RateLimiter::for('live', fn (Request $request) => Limit::perMinute(60)->by('live:'.($request->user()?->id ?? $request->ip())));
    }

    /**
     * Refuse to boot a misconfigured production app (debug on would leak stack traces and env values;
     * a missing key, URL or database would fail later in confusing ways). See ProductionGuard.
     */
    private function guardProductionConfig(): void
    {
        if ($this->app->isProduction() && $problems = ProductionGuard::problems()) {
            throw new RuntimeException('Refusing to boot in production: '.implode('; ', $problems).'.');
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
