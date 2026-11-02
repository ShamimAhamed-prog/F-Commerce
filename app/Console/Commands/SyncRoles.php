<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\RoleCatalog;
use Illuminate\Console\Command;

class SyncRoles extends Command
{
    protected $signature = 'fc:sync-roles';

    protected $description = 'Sync config/permissions.php roles and permissions into the database (idempotent)';

    public function handle(): int
    {
        RoleCatalog::sync();
        $this->info('Roles and permissions synced.');

        return self::SUCCESS;
    }
}
