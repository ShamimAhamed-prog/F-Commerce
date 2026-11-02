<?php

namespace App\Providers;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
    }

    public function boot(): void
    {
        // Browser tests must exercise the production build (service worker, CSP). A developer's running
        // `npm run dev` (public/hot) must never redirect them to the Vite dev server.
        if ($this->app->environment('e2e')) {
            Vite::useHotFile(storage_path('framework/vite-disabled-hot'));
        }

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(config('security.login_per_minute'))->by($r->ip()));
        RateLimiter::for('image-uploads', fn (Request $r) => Limit::perMinute(config('catalog.uploads_per_minute'))->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(config('security.register_per_minute'))->by($r->ip()));

        // Catalogue permissions are decided by the user's role in the ACTIVE tenant.
        // Super admins get no implicit tenant access (spec §3.2 #9).
        Gate::before(function (?User $user, string $ability) {
            if ($user && in_array($ability, config('permissions.permissions'), true)) {
                return $user->hasTenantPermission($ability);
            }

            return null;
        });
    }
}
