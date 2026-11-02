<?php

use App\Domain\Tenancy\RoleCatalog;
use Illuminate\Support\Facades\Gate;

it('gives the owner every catalogue permission', function () {
    [$user, $tenant] = memberOf();
    asTenant($tenant);

    foreach (config('permissions.permissions') as $perm) {
        expect(Gate::forUser($user)->allows($perm))->toBeTrue($perm);
    }
});

it('enforces viewer as read-only', function () {
    [$user, $tenant] = memberOf(role: 'viewer');
    asTenant($tenant);
    $g = Gate::forUser($user);

    expect($g->allows('orders.view'))->toBeTrue()
        ->and($g->allows('orders.create'))->toBeFalse()
        ->and($g->allows('products.images.view'))->toBeTrue()
        ->and($g->allows('products.images.manage'))->toBeFalse()
        ->and($g->allows('accounting.post'))->toBeFalse();
});

it('limits image management to the right roles', function () {
    foreach (['owner' => true, 'admin' => true, 'manager' => true, 'inventory_staff' => true, 'delivery_staff' => false, 'viewer' => false, 'accounts_staff' => false] as $role => $expected) {
        [$user, $tenant] = memberOf(role: $role);
        asTenant($tenant);
        expect(Gate::forUser($user)->allows('products.images.manage'))->toBe($expected, $role);
    }
});

it('hides profit and cost from staff who lack the permission', function () {
    foreach (['order_staff', 'inventory_staff', 'delivery_staff', 'viewer'] as $role) {
        [$user, $tenant] = memberOf(role: $role);
        asTenant($tenant);
        expect(Gate::forUser($user)->allows('profit.view'))->toBeFalse($role);
    }
});

it('scopes permissions to the active tenant, not across tenants', function () {
    [$user, $a] = memberOf(role: 'owner');
    [, $b] = memberOf(role: 'viewer', user: $user);

    asTenant($a);
    expect(Gate::forUser($user->fresh())->allows('orders.create'))->toBeTrue();
    asTenant($b);
    expect(Gate::forUser($user->fresh())->allows('orders.create'))->toBeFalse();
});

it('gives super admins no implicit tenant access', function () {
    [$user, $tenant] = memberOf(role: 'viewer');
    $user->forceFill(['is_super_admin' => true])->save();
    asTenant($tenant);

    expect(Gate::forUser($user)->allows('orders.create'))->toBeFalse();
});

it('keeps role sync idempotent', function () {
    RoleCatalog::sync();
    RoleCatalog::sync();
    expect(\App\Models\Role::count())->toBe(count(config('permissions.roles')));
});

it('grants and revokes super admin only via the audited CLI command', function () {
    [$user] = memberOf();

    $this->artisan('fc:make-super-admin', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->is_super_admin)->toBeTrue();
    expect(\App\Models\AuditLog::where('action', 'platform.super_admin_granted')->exists())->toBeTrue();

    $this->artisan('fc:make-super-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->is_super_admin)->toBeFalse();
});
