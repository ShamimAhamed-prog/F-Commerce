<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active tenant from the user's session selection and VERIFY
 * membership on every request (spec §3.2 #2). A raw tenant_id sent by the
 * client is never read.
 */
class ResolveTenant
{
    public function __construct(private CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->forget();
        $user = $request->user();

        if ($user && $request->hasSession()) {
            $tenant = $this->selected($request, $user);
            if ($tenant) {
                $this->current->set($tenant);
                $request->session()->put('tenant_id', $tenant->id);
            } else {
                $request->session()->forget('tenant_id');
            }
        }

        return $next($request);
    }

    private function selected(Request $request, $user): ?Tenant
    {
        $id = $request->session()->get('tenant_id');
        if ($id) {
            $tenant = $user->tenants()->where('tenants.id', $id)->first();
            if ($tenant && $tenant->isActive()) {
                return $tenant;
            }
        }

        $memberships = $user->tenants()->where('tenants.status', 'active')->limit(2)->get();

        return $memberships->count() === 1 ? $memberships->first() : null;
    }
}
