<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\Audit;
use App\Domain\Shipping\ConnectionResult;
use App\Domain\Shipping\SteadfastProvider;
use App\Http\Controllers\Controller;
use App\Models\CourierIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Integrations → Courier. Each tenant connects its OWN merchant account. */
class CourierController extends Controller
{
    private function authorizeManage(): void
    {
        abort_unless(Gate::allows('integrations.manage'), 403);
    }

    private function provider(string $key): string
    {
        abort_unless(config("couriers.$key"), 404);

        return $key;
    }

    public function index(): Response
    {
        $this->authorizeManage();
        $connected = CourierIntegration::all()->keyBy('provider');

        return Inertia::render('Settings/Couriers', [
            'providers' => collect(array_keys(array_filter(config('couriers'), 'is_array')))->map(fn ($key) => [
                'key' => $key, 'label' => config("couriers.$key.label"),
                'fields' => collect(config("couriers.$key.fields"))->map(fn ($label, $f) => ['key' => $f, 'label' => $label])->values(),
                'integration' => ($i = $connected[$key] ?? null) ? [
                    'status' => $i->status, 'masked' => $i->masked(), 'last_tested_at' => $i->last_tested_at, 'last_error_code' => $i->last_error_code,
                    'display_name' => $i->display_name,
                ] : null,
            ])->values(),
        ]);
    }

    /** Save (or rotate) credentials, then verify them. Blank fields on an existing connection keep the stored value. */
    public function save(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeManage();
        $this->provider($provider);
        $fields = array_keys(config("couriers.$provider.fields"));
        $existing = CourierIntegration::where('provider', $provider)->first();

        $rules = ['display_name' => ['nullable', 'string', 'max:100']];
        foreach ($fields as $f) {
            $rules[$f] = [$existing ? 'nullable' : 'required', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/']; // printable ASCII, no spaces
        }
        $d = $request->validate($rules);

        $creds = [];
        foreach ($fields as $f) {
            $creds[$f] = isset($d[$f]) && $d[$f] !== '' ? trim($d[$f]) : ($existing->credentials[$f] ?? '');
        }

        $integration = CourierIntegration::updateOrCreate(['provider' => $provider], [
            'display_name' => ($d['display_name'] ?? null) ?: config("couriers.$provider.label"),
            'credentials' => $creds, 'status' => 'pending', 'last_error_code' => null,
            'updated_by' => $request->user()->id, 'created_by' => $existing?->created_by ?? $request->user()->id,
        ]);
        Audit::record($existing ? 'courier.credentials_rotated' : 'courier.connected', $integration, new: ['provider' => $provider]); // never the secrets

        return $this->verify($integration);
    }

    public function test(string $provider): RedirectResponse
    {
        $this->authorizeManage();
        $integration = CourierIntegration::where('provider', $this->provider($provider))->firstOrFail();

        return $this->verify($integration);
    }

    public function destroy(string $provider): RedirectResponse
    {
        $this->authorizeManage();
        $integration = CourierIntegration::where('provider', $this->provider($provider))->firstOrFail();
        $integration->delete();
        Audit::record('courier.disconnected', null, new: ['provider' => $provider]);

        return back()->with('success', __('Disconnected.'));
    }

    private function verify(CourierIntegration $integration): RedirectResponse
    {
        // Providers lock a client out after repeated bad credentials, so never hammer them.
        $key = "courier-test:{$integration->id}";
        if (RateLimiter::tooManyAttempts($key, config('couriers.max_failed_tests_per_5_min'))) {
            return back()->with('error', __('Too many failed attempts. Wait a few minutes and check the keys before testing again.'));
        }

        $result = match ($integration->provider) {
            'steadfast' => (new SteadfastProvider($integration))->testConnection(),
            default => new ConnectionResult(false, 'unsupported', 'Unsupported provider.'),
        };

        $result->ok ? RateLimiter::clear($key) : RateLimiter::hit($key, 300);
        $integration->update([
            'status' => $result->ok ? 'connected' : 'failed', 'last_tested_at' => now(), 'last_error_code' => $result->ok ? null : $result->code,
        ]);
        Audit::record('courier.tested', $integration, new: ['provider' => $integration->provider, 'ok' => $result->ok, 'code' => $result->code]);

        return back()->with($result->ok ? 'success' : 'error', $result->message);
    }
}
