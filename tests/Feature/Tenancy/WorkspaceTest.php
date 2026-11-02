<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('does not let a user switch into a tenant they do not belong to', function () {
    [$user] = memberOf();
    $other = Tenant::factory()->create();
    memberOf($other);

    $this->actingAs($user)->post("/app/workspaces/{$other->id}/switch")->assertNotFound();
    expect(session('tenant_id'))->not->toBe($other->id);
});

it('switches into a member tenant and audits it', function () {
    [$user, $a] = memberOf();
    memberOf(null, 'owner', $user);
    $b = $user->tenants()->where('tenants.id', '!=', $a->id)->first();

    $this->actingAs($user)->withSession(['tenant_id' => $a->id])
        ->post("/app/workspaces/{$b->id}/switch")->assertRedirect('/app/dashboard');

    expect(session('tenant_id'))->toBe($b->id);
    expect(AuditLog::where('action', 'tenant.switched')->where('tenant_id', $b->id)->exists())->toBeTrue();
});

it('ignores a session tenant the user was removed from', function () {
    [$user, $a] = memberOf();
    $other = Tenant::factory()->create();
    memberOf($other);

    $this->actingAs($user)->withSession(['tenant_id' => $other->id])
        ->get('/app/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('tenant.id', $a->id));
});

it('never trusts a tenant_id sent by the client', function () {
    [$user, $a] = memberOf();
    $other = Tenant::factory()->create();

    $this->actingAs($user)->withSession(['tenant_id' => $a->id])
        ->withHeaders(['X-Tenant-ID' => (string) $other->id])
        ->get('/app/dashboard?tenant_id='.$other->id)
        ->assertInertia(fn (Assert $p) => $p->where('tenant.id', $a->id));
});

it('sends users with several workspaces and no selection to the picker', function () {
    [$user] = memberOf();
    memberOf(null, 'owner', $user);

    $this->actingAs($user)->get('/app/dashboard')->assertRedirect('/app/workspaces');
});

it('blocks suspended tenants', function () {
    [$user, $tenant] = memberOf();
    $tenant->update(['status' => 'suspended']);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get('/app/dashboard')->assertRedirect('/app/workspaces');
});

it('creates a workspace with the creator as owner', function () {
    [$user] = memberOf();

    $this->actingAs($user)->post('/app/workspaces', ['name' => 'Second Shop'])->assertRedirect('/app/dashboard');

    $t = Tenant::where('name', 'Second Shop')->firstOrFail();
    expect($user->roleSlugIn($t->id))->toBe('owner');
});
