<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $request->session()->forget('tenant_id');

        Audit::record('auth.login', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function register(RegisterRequest $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validated();

        $tenant = DB::transaction(function () use ($data, $provisioner, &$user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            return $provisioner->create($user, ['name' => $data['workspace_name'], 'phone' => $data['phone'] ?? null]);
        });

        event(new Registered($user)); // sends the verification email
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        Audit::record('auth.registered', $user, tenantId: $tenant->id);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('auth.logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
