<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = app(CurrentTenant::class)->get();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'is_super_admin' => (bool) $user->is_super_admin,
                ] : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'lakh' => (bool) ($tenant->settings['lakh_grouping'] ?? false),
            ] : null,
            'tenants' => fn () => $user
                ? $user->tenants()->where('tenants.status', 'active')->orderBy('tenants.name')
                    ->get(['tenants.id', 'tenants.name', 'tenants.slug'])
                    ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])
                : [],
            'permissions' => $user && $tenant ? $user->permissionsIn($tenant->id) : [],
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
