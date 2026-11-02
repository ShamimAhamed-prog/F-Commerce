<?php

namespace App\Console\Commands;

use App\Domain\Audit\Audit;
use App\Models\User;
use Illuminate\Console\Command;

/** Super-admin is granted only here, explicitly and audited (spec §3.2 #9). Never via HTTP. */
class MakeSuperAdmin extends Command
{
    protected $signature = 'fc:make-super-admin {email} {--revoke}';

    protected $description = 'Grant or revoke platform super-admin for an existing user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();
        Audit::record($grant ? 'platform.super_admin_granted' : 'platform.super_admin_revoked', $user, meta: ['via' => 'cli']);
        $this->info(($grant ? 'Granted' : 'Revoked').' super-admin: '.$user->email);

        return self::SUCCESS;
    }
}
