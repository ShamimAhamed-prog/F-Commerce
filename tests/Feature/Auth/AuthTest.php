<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => $this->withoutVite());

it('registers a user with a workspace and owner role', function () {
    $this->post('/auth/register', [
        'name' => 'Rahim', 'email' => 'rahim@example.com', 'workspace_name' => 'Rahim Store',
        'password' => 'secret123', 'password_confirmation' => 'secret123',
    ])->assertRedirect('/app/dashboard');

    $user = User::where('email', 'rahim@example.com')->firstOrFail();
    $tenant = Tenant::where('name', 'Rahim Store')->firstOrFail();
    expect($user->roleSlugIn($tenant->id))->toBe('owner')
        ->and($tenant->currency)->toBe('BDT')
        ->and($tenant->timezone)->toBe('Asia/Dhaka')
        ->and($user->is_super_admin)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('cannot self-assign super admin on registration', function () {
    $this->post('/auth/register', [
        'name' => 'Evil', 'email' => 'evil@example.com', 'workspace_name' => 'X', 'is_super_admin' => 1,
        'password' => 'secret123', 'password_confirmation' => 'secret123',
    ]);

    expect(User::where('email', 'evil@example.com')->value('is_super_admin'))->toBeFalsy();
});

it('rejects weak passwords', function () {
    $this->post('/auth/register', [
        'name' => 'A', 'email' => 'a@example.com', 'workspace_name' => 'X',
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});

it('logs in with valid credentials and rejects invalid ones', function () {
    [$user] = memberOf();

    $this->post('/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/auth/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('rate limits repeated failed logins', function () {
    [$user] = memberOf();
    RateLimiter::clear(\Illuminate\Support\Str::transliterate(strtolower($user->email).'|127.0.0.1'));

    foreach (range(1, 5) as $i) {
        $this->post('/auth/login', ['email' => $user->email, 'password' => 'wrong']);
    }
    $this->post('/auth/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('logs out, invalidates the session and audits', function () {
    [$user, $tenant] = memberOf();

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/auth/logout')->assertRedirect('/auth/login');

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.logout')->exists())->toBeTrue();
});

it('redirects guests to login', function () {
    $this->get('/app/dashboard')->assertRedirect('/auth/login');
});

it('never writes passwords into the audit trail', function () {
    \App\Domain\Audit\Audit::record('x', null, new: ['password' => 'hunter2', 'name' => 'ok']);

    expect(AuditLog::latest('id')->first()->new_values)->toBe(['password' => '[redacted]', 'name' => 'ok']);
});

it('makes audit logs immutable', function () {
    $log = \App\Domain\Audit\Audit::record('x');
    expect(fn () => $log->update(['action' => 'y']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('throttles registration per IP', function () {
    config(['security.register_per_minute' => 2]);
    foreach (range(1, 2) as $i) {
        $this->post('/auth/register', []);
    }
    $this->post('/auth/register', [])->assertStatus(429);
});
