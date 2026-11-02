// OFF-7: Background Sync / retry queues are prohibited. Fails CI if any appear in app/SW sources.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const banned = [/BackgroundSyncPlugin/, /workbox-background-sync/, /\.sync\.register/, /periodicSync/, /addEventListener\(\s*['"]sync['"]/];
const found = [];

function walk(dir) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) walk(p);
        else if (/\.(ts|js|vue|mjs)$/.test(name) && !/\.test\.ts$/.test(name)) {
            const src = readFileSync(p, 'utf8');
            for (const re of banned) if (re.test(src)) found.push(`${p}: ${re}`);
        }
    }
}

walk('resources/js');
if (found.length) {
    console.error('Prohibited background sync usage (OFF-7):\n' + found.join('\n'));
    process.exit(1);
}
console.log('OK: no background sync usage.');
