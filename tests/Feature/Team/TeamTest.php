<?php

use App\Mail\InvitationMail;
use App\Models\Product;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->withoutVite();
    [$this->owner, $this->tenant] = memberOf();
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    $this->roleId = fn (string $slug) => \App\Models\Role::whereNull('tenant_id')->where('slug', $slug)->value('id');
    /** invite and capture the raw token from the mail */
    $this->invite = function (string $email, string $role = 'manager', $by = null) {
        $token = null;
        Mail::fake();
        $res = ($this->as)($by)->post('/app/settings/staff/invite', ['email' => $email, 'role_id' => ($this->roleId)($role)]);
        Mail::assertSent(InvitationMail::class, function ($m) use (&$token) { $token = basename($m->url); return true; });

        auth()->forgetGuards(); // later requests in the test act as a guest unless they actingAs() again
        session()->flush();

        return [$res, $token];
    };
});

// ------------------------------------------------------------------ password reset
it('resets a password through the emailed token and signs other devices out', function () {
    Notification::fake();
    $u = User::factory()->create(['email' => 'a@example.com']);
    DB::table('sessions')->insert(['id' => 'x', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);

    $this->post('/auth/forgot-password', ['email' => 'a@example.com'])->assertSessionHas('success');
    $token = null;
    Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });

    $this->post('/auth/reset-password', ['token' => 'bad', 'email' => 'a@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHasErrors('email');
    $this->post('/auth/reset-password', ['token' => $token, 'email' => 'a@example.com', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
    $this->post('/auth/reset-password', ['token' => $token, 'email' => 'a@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertRedirect('/auth/login');

    expect(\Hash::check('newpass123', $u->fresh()->password))->toBeTrue()->and(DB::table('sessions')->where('user_id', $u->id)->count())->toBe(0);
    $this->post('/auth/reset-password', ['token' => $token, 'email' => 'a@example.com', 'password' => 'another123', 'password_confirmation' => 'another123'])->assertSessionHasErrors('email'); // single use
});

it('gives the same answer for unknown emails (no account enumeration)', function () {
    Notification::fake();
    $a = $this->post('/auth/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('success');
    Notification::assertNothingSent();
    expect(session('success'))->toContain('If an account exists');
});

// ------------------------------------------------------------ email verification policy
it('sends a verification email on registration', function () {
    Notification::fake();
    $this->post('/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'workspace_name' => 'W', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    Notification::assertSentTo(User::where('email', 'n@example.com')->first(), VerifyEmail::class);
});

it('holds unverified users on the verification screen only when the policy is on', function () {
    $u = User::factory()->unverified()->create();
    memberOf($this->tenant, 'owner', $u);

    $this->actingAs($u)->withSession(['tenant_id' => $this->tenant->id])->get('/app/dashboard')->assertOk(); // policy off (default)

    config(['security.require_email_verification' => true]);
    $this->actingAs($u)->withSession(['tenant_id' => $this->tenant->id])->get('/app/dashboard')->assertRedirect('/auth/verify-email');
    $this->actingAs($u)->get('/auth/verify-email')->assertOk();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $u->id, 'hash' => sha1($u->email)]);
    $this->actingAs($u)->get($url)->assertRedirect('/app/dashboard');
    expect($u->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->actingAs($u->fresh())->withSession(['tenant_id' => $this->tenant->id])->get('/app/dashboard')->assertOk();

    $bad = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $u->id, 'hash' => 'wrong']);
    $u2 = User::factory()->unverified()->create();
    $this->actingAs($u2)->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $u2->id, 'hash' => 'wrong']))->assertForbidden();
});

// -------------------------------------------------------------------- invitations
it('invites a new person who creates an account via the link and joins with the role', function () {
    [$res, $token] = ($this->invite)('New@Example.com', 'manager');
    $res->assertSessionHasNoErrors();
    $inv = TenantInvitation::first();
    expect($inv->email)->toBe('new@example.com')->and($inv->token_hash)->not->toBe($token)->and($inv->token_hash)->toBe(TenantInvitation::hash($token));

    $this->get("/invitations/{$token}")->assertInertia(fn ($a) => $a->where('state', 'register')->where('role', 'Manager'));
    $this->post("/invitations/{$token}/accept", ['name' => 'Nina', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertRedirect('/app/dashboard');

    $u = User::where('email', 'new@example.com')->first();
    expect($u->roleSlugIn($this->tenant->id))->toBe('manager')->and($u->hasVerifiedEmail())->toBeTrue();
    $this->assertAuthenticatedAs($u);
    $this->post('/auth/logout');
    $this->post("/invitations/{$token}/accept", ['name' => 'X', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertNotFound(); // single use
});

it('lets an existing user accept only when signed in as the invited address', function () {
    $existing = User::factory()->create(['email' => 'e@example.com']);
    [, $token] = ($this->invite)('e@example.com', 'viewer');

    $this->get("/invitations/{$token}")->assertInertia(fn ($a) => $a->where('state', 'login'));
    $this->post("/invitations/{$token}/accept")->assertForbidden(); // guest cannot take over an existing account
    $other = User::factory()->create();
    $this->actingAs($other)->post("/invitations/{$token}/accept")->assertForbidden();
    $this->actingAs($other)->get("/invitations/{$token}")->assertInertia(fn ($a) => $a->where('state', 'wrong_account'));

    $this->actingAs($existing)->post("/invitations/{$token}/accept")->assertRedirect('/app/dashboard');
    expect($existing->roleSlugIn($this->tenant->id))->toBe('viewer');
});

it('rejects expired and revoked invitations', function () {
    [, $token] = ($this->invite)('x@example.com');
    TenantInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $this->get("/invitations/{$token}")->assertInertia(fn ($a) => $a->where('state', 'invalid'));
    $this->post("/invitations/{$token}/accept", ['name' => 'X', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertNotFound();

    [, $t2] = ($this->invite)('y@example.com');
    $id = TenantInvitation::where('email', 'y@example.com')->value('id');
    ($this->as)()->delete("/app/settings/staff/invitations/{$id}")->assertRedirect();
    $this->get("/invitations/{$t2}")->assertInertia(fn ($a) => $a->where('state', 'invalid'));
});

it('re-inviting replaces the pending invitation and members cannot be invited twice', function () {
    ($this->invite)('r@example.com');
    ($this->invite)('r@example.com');
    expect(TenantInvitation::where('email', 'r@example.com')->count())->toBe(1);
    ($this->as)()->post('/app/settings/staff/invite', ['email' => $this->owner->email, 'role_id' => ($this->roleId)('viewer')])->assertSessionHasErrors('email');
});

it('limits who can manage the team and who can grant owner', function () {
    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->get('/app/settings/staff')->assertForbidden();
    ($this->as)($mgr)->post('/app/settings/staff/invite', ['email' => 'z@example.com', 'role_id' => ($this->roleId)('viewer')])->assertForbidden();

    [$admin] = memberOf($this->tenant, 'admin');
    ($this->as)($admin)->post('/app/settings/staff/invite', ['email' => 'z@example.com', 'role_id' => ($this->roleId)('owner')])->assertForbidden(); // admin cannot mint owners
    ($this->as)($admin)->patch("/app/settings/staff/{$mgr->id}", ['role_id' => ($this->roleId)('owner')])->assertForbidden();
    ($this->as)($admin)->patch("/app/settings/staff/{$this->owner->id}", ['role_id' => ($this->roleId)('viewer')])->assertForbidden(); // nor touch owners
    ($this->as)($admin)->delete("/app/settings/staff/{$this->owner->id}")->assertForbidden();
    ($this->as)($admin)->patch("/app/settings/staff/{$mgr->id}", ['role_id' => ($this->roleId)('viewer')])->assertRedirect();
    expect($mgr->roleSlugIn($this->tenant->id))->toBe('viewer');
});

it('never leaves a workspace without an owner and removal revokes access at once', function () {
    ($this->as)()->patch("/app/settings/staff/{$this->owner->id}", ['role_id' => ($this->roleId)('admin')])->assertSessionHasErrors('role_id');
    ($this->as)()->delete("/app/settings/staff/{$this->owner->id}")->assertSessionHasErrors('member');

    [$staff] = memberOf($this->tenant, 'order_staff');
    ($this->as)($staff)->get('/app/orders')->assertOk();
    ($this->as)()->delete("/app/settings/staff/{$staff->id}")->assertRedirect();
    ($this->as)($staff)->get('/app/orders')->assertRedirect('/app/workspaces'); // membership re-verified every request
});

it('keeps team management inside the tenant', function () {
    [$u2, $t2] = memberOf();
    [, $token] = ($this->invite)('q@example.com');
    $invId = TenantInvitation::first()->id;
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);

    $b()->delete("/app/settings/staff/invitations/{$invId}")->assertNotFound();
    $b()->patch("/app/settings/staff/{$this->owner->id}", ['role_id' => ($this->roleId)('viewer')])->assertNotFound();
    $b()->delete("/app/settings/staff/{$this->owner->id}")->assertNotFound();
    $b()->get('/app/settings/staff')->assertInertia(fn ($a) => $a->has('members', 1)->has('invitations', 0));
});

it('shows the built-in roles matrix', function () {
    ($this->as)()->get('/app/settings/roles')->assertInertia(fn ($a) => $a->component('Settings/Roles')->has('roles', 8));
});

// ---------------------------------------------------------------- workspace settings
it('saves workspace settings that drive the catalog rules, audited and permission-gated', function () {
    ($this->as)()->put('/app/settings', [
        'name' => 'Renamed Shop', 'phone' => '01711111111', 'address' => 'Dhaka', 'timezone' => 'Asia/Dhaka',
        'require_image_before_activation' => true, 'max_images_per_product' => 2, 'lakh_grouping' => true,
    ])->assertSessionHasNoErrors();
    expect($this->tenant->fresh()->name)->toBe('Renamed Shop')->and($this->tenant->fresh()->settings['max_images_per_product'])->toBe(2);

    asTenant($this->tenant->fresh());
    $p = Product::factory()->create();
    expect(app(\App\Domain\Catalog\ImageService::class)->maxImages())->toBe(2);
    ($this->as)()->post('/app/products', ['name' => 'A', 'sku' => 'S-1', 'type' => 'simple', 'selling_price' => '1', 'status' => 'active'])->assertSessionHasErrors('status');
    expect(\App\Models\AuditLog::where('action', 'workspace.settings_updated')->exists())->toBeTrue();

    ($this->as)()->put('/app/settings', ['name' => 'X', 'timezone' => 'Nope/Zone', 'require_image_before_activation' => false, 'max_images_per_product' => 99, 'lakh_grouping' => false])
        ->assertSessionHasErrors(['timezone', 'max_images_per_product']);
    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->get('/app/settings')->assertForbidden();
});
