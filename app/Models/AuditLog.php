<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Intentionally NOT tenant-scoped via the global scope so that
 * platform/super-admin and tenant-less events (login) can be recorded;
 * tenant views must filter by tenant_id explicitly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are immutable.'));
    }
}
