<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;

/**
 * Holds the active tenant for the current request / job (spec §3.2 #2, #7).
 * Never populated from a raw request value: only from a verified membership
 * (ResolveTenant middleware) or an explicit, trusted call (jobs, tests).
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /** Run a callback inside a tenant context, restoring the previous one. */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
