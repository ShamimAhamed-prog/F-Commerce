<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\Audit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioner
{
    /** Create a workspace and make $owner its Tenant Owner. */
    public function create(User $owner, array $data): Tenant
    {
        return DB::transaction(function () use ($owner, $data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'timezone' => $data['timezone'] ?? 'Asia/Dhaka',
                'currency' => 'BDT',
            ]);

            $owner->tenants()->attach($tenant->id, [
                'role_id' => RoleCatalog::systemRole('owner')->id,
            ]);

            app(\App\Domain\Tenancy\CurrentTenant::class)->run($tenant, fn () => \App\Domain\Accounting\ChartOfAccounts::ensure());
            Audit::record('tenant.created', $tenant, new: ['name' => $tenant->name], tenantId: $tenant->id);

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
