import { execSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';

export default async function globalSetup() {
    closeSync(openSync('database/e2e.sqlite', 'w'));
    execSync('php artisan migrate:fresh --force --env=e2e', { stdio: 'inherit' });
}
