<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped on purpose: the accept link is opened outside any tenant context.
 * Management queries MUST filter by the current tenant explicitly (see StaffController).
 */
class TenantInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function role(): BelongsTo { return $this->belongsTo(Role::class); }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function usable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
