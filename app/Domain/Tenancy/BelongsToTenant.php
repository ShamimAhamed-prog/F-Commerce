<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Apply to every tenant-owned model (spec §3.2 #1, #3).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $current = app(CurrentTenant::class)->id();

            if ($model->tenant_id === null) {
                if ($current === null) {
                    throw new LogicException('Cannot create '.class_basename($model).' without an active tenant.');
                }
                $model->tenant_id = $current;
            } elseif ($current !== null && (int) $model->tenant_id !== $current) {
                throw new LogicException('Cross-tenant write blocked for '.class_basename($model).'.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id is immutable.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
