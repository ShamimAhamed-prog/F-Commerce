<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Role;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    private function tid(): int
    {
        return app(CurrentTenant::class)->id();
    }

    private function authorizeManage(): void
    {
        abort_unless(Gate::allows('staff.manage'), 403);
    }

    private function members()
    {
        return DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')->join('roles', 'roles.id', '=', 'tenant_user.role_id')
            ->where('tenant_user.tenant_id', $this->tid());
    }

    private function actorIsOwner(Request $request): bool
    {
        return $request->user()->roleSlugIn($this->tid()) === 'owner';
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage();
        $roles = Role::whereNull('tenant_id')->orderBy('id')->get(['id', 'slug', 'name']);

        return Inertia::render('Settings/Staff', [
            'members' => $this->members()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'roles.slug as role', 'roles.name as role_name'])
                ->map(fn ($m) => (array) $m),
            'invitations' => TenantInvitation::with('role:id,name')->where('tenant_id', $this->tid())->whereNull('accepted_at')->where('expires_at', '>', now())->latest('id')->get()
                ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role->name, 'expires_at' => $i->expires_at]),
            'roles' => $roles->filter(fn ($r) => $r->slug !== 'owner' || $this->actorIsOwner($request))->values(),
            'me' => $request->user()->id,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'role_id' => ['required', 'integer']]);
        $email = Str::lower($d['email']);
        $role = Role::whereNull('tenant_id')->findOrFail($d['role_id']);

        // Only an owner can hand out the owner role; nobody can escalate beyond their own standing.
        if ($role->slug === 'owner' && ! $this->actorIsOwner($request)) {
            abort(403);
        }
        if ($this->members()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a member.']);
        }

        TenantInvitation::where('tenant_id', $this->tid())->where('email', $email)->whereNull('accepted_at')->delete(); // replace any pending one
        $token = Str::random(48);
        $inv = TenantInvitation::create([
            'tenant_id' => $this->tid(), 'email' => $email, 'role_id' => $role->id, 'token_hash' => TenantInvitation::hash($token),
            'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(config('security.invitation_ttl_days')),
        ]);
        Mail::to($email)->send(new InvitationMail($inv->load(['tenant', 'role']), route('invitations.show', $token), $request->user()->name));
        Audit::record('staff.invited', $inv->tenant, new: ['email' => $email, 'role' => $role->slug]);

        return back()->with('success', __('Invitation sent to :email.', ['email' => $email]));
    }

    public function revoke(TenantInvitation $invitation): RedirectResponse
    {
        $this->authorizeManage();
        abort_unless($invitation->tenant_id === $this->tid(), 404);
        $invitation->delete();
        Audit::record('staff.invitation_revoked', null, new: ['email' => $invitation->email]);

        return back()->with('success', __('Invitation revoked.'));
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage();
        $d = $request->validate(['role_id' => ['required', 'integer']]);
        $role = Role::whereNull('tenant_id')->findOrFail($d['role_id']);
        $current = $this->members()->where('users.id', $user->id)->value('roles.slug') ?? abort(404);

        if (($role->slug === 'owner' || $current === 'owner') && ! $this->actorIsOwner($request)) {
            abort(403);
        }
        if ($current === 'owner' && $role->slug !== 'owner' && $this->members()->where('roles.slug', 'owner')->count() <= 1) {
            throw ValidationException::withMessages(['role_id' => 'A workspace needs at least one owner.']);
        }

        DB::table('tenant_user')->where('tenant_id', $this->tid())->where('user_id', $user->id)->update(['role_id' => $role->id, 'updated_at' => now()]);
        Audit::record('staff.role_changed', null, ['role' => $current], ['role' => $role->slug, 'user_id' => $user->id]);

        return back()->with('success', __('Role updated.'));
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage();
        $current = $this->members()->where('users.id', $user->id)->value('roles.slug') ?? abort(404);

        if ($current === 'owner' && ! $this->actorIsOwner($request)) {
            abort(403);
        }
        if ($current === 'owner' && $this->members()->where('roles.slug', 'owner')->count() <= 1) {
            throw ValidationException::withMessages(['member' => 'A workspace needs at least one owner.']);
        }

        DB::table('tenant_user')->where('tenant_id', $this->tid())->where('user_id', $user->id)->delete();
        Audit::record('staff.removed', null, ['role' => $current], ['user_id' => $user->id]);

        return back()->with('success', __('Member removed.'));
    }

    /** Read-only view of the system roles and what each may do. */
    public function roles(): Response
    {
        $this->authorizeManage();

        return Inertia::render('Settings/Roles', [
            'roles' => Role::whereNull('tenant_id')->with('permissions:id,slug')->orderBy('id')->get()
                ->map(fn ($r) => ['name' => $r->name, 'slug' => $r->slug, 'permissions' => $r->permissions->pluck('slug')->sort()->values()]),
            'all' => config('permissions.permissions'),
        ]);
    }
}
