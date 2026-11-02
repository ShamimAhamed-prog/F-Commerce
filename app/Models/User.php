<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Tenancy\CurrentTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /** @var array<int, list<string>> per-request memo of permission slugs by tenant */
    private array $permissionMemo = [];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role_id')->withTimestamps();
    }

    public function belongsToTenant(int $tenantId): bool
    {
        return DB::table('tenant_user')
            ->where('user_id', $this->getKey())
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /** @return list<string> */
    public function permissionsIn(int $tenantId): array
    {
        return $this->permissionMemo[$tenantId] ??= DB::table('tenant_user')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'tenant_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('tenant_user.user_id', $this->getKey())
            ->where('tenant_user.tenant_id', $tenantId)
            ->pluck('permissions.slug')
            ->all();
    }

    public function roleSlugIn(int $tenantId): ?string
    {
        return DB::table('tenant_user')
            ->join('roles', 'roles.id', '=', 'tenant_user.role_id')
            ->where('tenant_user.user_id', $this->getKey())
            ->where('tenant_user.tenant_id', $tenantId)
            ->value('roles.slug');
    }

    public function hasTenantPermission(string $permission, ?int $tenantId = null): bool
    {
        $tenantId ??= app(CurrentTenant::class)->id();

        return $tenantId !== null && in_array($permission, $this->permissionsIn($tenantId), true);
    }
}
