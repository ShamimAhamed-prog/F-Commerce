<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Workspaces/Index');
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $tenant = $provisioner->create($request->user(), $data);
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', __('Workspace created.'));
    }

    /** Switch only into a workspace the user is verifiably a member of. */
    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->isActive() && $request->user()->belongsToTenant($tenant->id), 404);

        $request->session()->put('tenant_id', $tenant->id);
        Audit::record('tenant.switched', $tenant, tenantId: $tenant->id);

        return redirect()->route('dashboard');
    }
}
