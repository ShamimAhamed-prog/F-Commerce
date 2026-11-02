<?php

namespace App\Domain\Tenancy;

use App\Models\Permission;
use App\Models\Role;

/** Syncs config/permissions.php into the database. Idempotent. */
class RoleCatalog
{
    public static function sync(): void
    {
        $all = config('permissions.permissions');
        $ids = [];
        foreach ($all as $slug) {
            $ids[$slug] = Permission::firstOrCreate(['slug' => $slug])->id;
        }

        foreach (config('permissions.roles') as $slug => $def) {
            $role = Role::firstOrCreate(
                ['tenant_id' => null, 'slug' => $slug],
                ['name' => $def['name'], 'is_system' => true],
            );
            $slugs = in_array('*', $def['permissions'], true) ? $all : $def['permissions'];
            $role->permissions()->sync(array_map(fn ($s) => $ids[$s], $slugs));
        }
    }

    public static function ensure(): void
    {
        if (! Role::whereNull('tenant_id')->where('slug', 'owner')->exists()) {
            self::sync();
        }
    }

    public static function systemRole(string $slug): Role
    {
        self::ensure();

        return Role::whereNull('tenant_id')->where('slug', $slug)->firstOrFail();
    }
}
