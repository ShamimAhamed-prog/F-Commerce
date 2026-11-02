<?php

namespace App\Jobs\Concerns;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\Tenant;

/** Jobs carry the tenant id and re-establish context (spec §3.2 #7). */
trait RunsInTenant
{
    public int $tenantId;

    protected function captureTenant(): void
    {
        $this->tenantId = app(CurrentTenant::class)->id()
            ?? throw new \LogicException('Cannot queue a tenant job without an active tenant.');
    }

    protected function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run(Tenant::findOrFail($this->tenantId), $fn);
    }
}
