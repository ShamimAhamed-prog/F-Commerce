<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(Gate::allows('settings.manage'), 403);
        $t = app(CurrentTenant::class)->get();

        return Inertia::render('Settings/Workspace', [
            'workspace' => [
                'name' => $t->name, 'phone' => $t->phone, 'address' => $t->address, 'timezone' => $t->timezone,
                'require_image_before_activation' => (bool) ($t->settings['require_image_before_activation'] ?? false),
                'max_images_per_product' => (int) ($t->settings['max_images_per_product'] ?? config('catalog.max_images_per_product')),
                'lakh_grouping' => (bool) ($t->settings['lakh_grouping'] ?? false),
            ],
            'maxImagesCap' => 20,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('settings.manage'), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:32'], 'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone:all'],
            'require_image_before_activation' => ['required', 'boolean'],
            'max_images_per_product' => ['required', 'integer', 'min:1', 'max:20'],
            'lakh_grouping' => ['required', 'boolean'],
        ]);

        $t = app(CurrentTenant::class)->get();
        $old = $t->only('name', 'phone', 'address', 'timezone') + ['settings' => $t->settings];
        $t->update([
            'name' => $d['name'], 'phone' => $d['phone'], 'address' => $d['address'], 'timezone' => $d['timezone'],
            'settings' => array_merge($t->settings ?? [], [
                'require_image_before_activation' => $d['require_image_before_activation'],
                'max_images_per_product' => $d['max_images_per_product'],
                'lakh_grouping' => $d['lakh_grouping'],
            ]),
        ]);
        Audit::record('workspace.settings_updated', $t, $old, $t->only('name', 'phone', 'address', 'timezone', 'settings'));

        return back()->with('success', __('Settings saved.'));
    }
}
