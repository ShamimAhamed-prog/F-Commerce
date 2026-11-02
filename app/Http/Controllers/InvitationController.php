<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Public accept flow. The raw token only exists in the emailed link; we look it up by hash. */
class InvitationController extends Controller
{
    private function find(string $token): ?TenantInvitation
    {
        return TenantInvitation::with(['tenant', 'role'])->where('token_hash', TenantInvitation::hash($token))->first();
    }

    public function show(Request $request, string $token): Response
    {
        $inv = $this->find($token);
        if (! $inv || ! $inv->usable() || ! $inv->tenant->isActive()) {
            return Inertia::render('Invitations/Show', ['state' => 'invalid']);
        }

        $user = $request->user();
        $exists = User::where('email', $inv->email)->exists();

        return Inertia::render('Invitations/Show', [
            'state' => $user ? (strcasecmp($user->email, $inv->email) === 0 ? 'join' : 'wrong_account') : ($exists ? 'login' : 'register'),
            'token' => $token,
            'email' => $inv->email,
            'workspace' => $inv->tenant->name,
            'role' => $inv->role->name,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $inv = $this->find($token);
        abort_unless($inv && $inv->usable() && $inv->tenant->isActive(), 404);

        $user = $request->user();
        if ($user) {
            abort_unless(strcasecmp($user->email, $inv->email) === 0, 403, 'This invitation was sent to a different email address.');
        } else {
            abort_if(User::where('email', $inv->email)->exists(), 403, 'Sign in to accept this invitation.');
            $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
            // Possession of the emailed token proves the address, so it is marked verified.
            $user = User::create(['name' => $d['name'], 'email' => $inv->email, 'password' => $d['password']]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        DB::transaction(function () use ($inv, $user) {
            // Single-use: consume atomically so a replay cannot add a second membership/role.
            $consumed = TenantInvitation::where('id', $inv->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);
            abort_unless($consumed, 404);
            $user->tenants()->syncWithoutDetaching([$inv->tenant_id => ['role_id' => $inv->role_id]]);
            Audit::record('staff.invitation_accepted', $inv->tenant, new: ['user_id' => $user->id, 'role' => $inv->role->slug], tenantId: $inv->tenant_id);
        });

        if (! Auth::check()) {
            Auth::login($user);
            $request->session()->regenerate();
        }
        $request->session()->put('tenant_id', $inv->tenant_id);

        return redirect()->route('dashboard')->with('success', __('Welcome to :name.', ['name' => $inv->tenant->name]));
    }
}
