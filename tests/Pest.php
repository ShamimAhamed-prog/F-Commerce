<?php

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\RoleCatalog;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/** Create a user who is a member of a new tenant with the given role. */
function memberOf(?Tenant $tenant = null, string $role = 'owner', ?User $user = null): array
{
    RoleCatalog::ensure();
    $user ??= User::factory()->create();
    $tenant ??= Tenant::factory()->create();
    $user->tenants()->attach($tenant->id, ['role_id' => RoleCatalog::systemRole($role)->id]);

    return [$user, $tenant];
}

function actingInTenant(User $user, Tenant $tenant): \Illuminate\Testing\TestResponse|\Tests\TestCase
{
    return test()->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
}

function asTenant(Tenant $tenant): void
{
    app(CurrentTenant::class)->set($tenant);
}
