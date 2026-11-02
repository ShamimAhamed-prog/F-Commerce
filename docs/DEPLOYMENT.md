# Deployment & Operations Runbook (Phase 0)

## Build
```
composer install --no-dev --optimize-autoloader
npm ci && npm run build      # outputs public/build and public/sw.js
php artisan migrate --force
php artisan fc:sync-roles
php artisan config:cache route:cache
```
Node is only needed on the build machine/CI. Upload `public/build` and `public/sw.js` with the release.

## HTTPS is mandatory
PWA install, service workers and secure cookies need it. Set `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`.

## Nginx essentials
```nginx
root /var/www/fcommerce/public;
location = /sw.js                { add_header Cache-Control "no-cache"; }
location = /manifest.webmanifest { default_type application/manifest+json; add_header Cache-Control "no-cache"; }
location ~ ^/offline\.(html|css|js)$ { add_header Cache-Control "no-cache"; }
location /build/ { add_header Cache-Control "public, max-age=31536000, immutable"; }
# later, only if a Trusted Web Activity wrapper is added: serve /.well-known/assetlinks.json
```
Apache: `public/.htaccess` already sets these headers.

## cPanel / no terminal: web maintenance URLs
When you cannot run `php artisan`, use the built-in maintenance route. It is **off by default** (always a plain 404).

1. In the server's `.env` set a long random key (32+ characters), e.g. `MAINTENANCE_KEY=<random 40+ chars>`.
   Generate one with any password generator. Do not reuse a password. If config is cached, run the first clear some other way
   or delete `bootstrap/cache/config.php` in File Manager once.
2. Open in your browser (replace `YOURKEY`):

| URL | What it does |
|-----|--------------|
| `https://your-domain/maintenance/clear?key=YOURKEY` | Clears app cache, config, route, view and event caches |
| `https://your-domain/maintenance/optimize?key=YOURKEY` | Clears, then rebuilds the config/route/view/event caches (faster production) |
| `https://your-domain/maintenance/migrate?key=YOURKEY` | Runs pending database migrations (`--force`) |
| `https://your-domain/maintenance/roles?key=YOURKEY` | Re-syncs the built-in roles and permissions |
| `https://your-domain/maintenance/storage-link?key=YOURKEY` | Creates the `public/storage` link (may be blocked by some hosts) |

3. The page answers with a short plain-text result (`OK` / `FAILED` per command).
4. **When you are done, empty `MAINTENANCE_KEY` again** (or remove the line) so the URL is dead. Use `optimize` after every deploy and
   after every `.env` change (config is cached, so `.env` edits only apply after `clear` or `optimize`).

Safety: any mistake (no key, wrong key, unknown action) returns the same 404; five wrong keys lock the caller's IP out for 15 minutes;
only the fixed commands above can run; the key is never echoed. The key travels in the URL, so it can appear in server logs and browser
history: that is why the key should be temporary. You can send it as an `X-Maintenance-Key` header instead of `?key=` if you use a tool such as curl.

cPanel notes: set the domain's document root to the project's `public/` folder, use PHP 8.2+, and upload `public/build` and `public/sw.js`
(built with `npm run build` elsewhere). Make `storage/` and `bootstrap/cache/` writable.

## Courier status sync
Parcels are checked every 15 minutes by `php artisan fc:courier-sync`, which runs from the Laravel scheduler, so the cron entry
`* * * * * php artisan schedule:run` (see below) must exist. No cron on your host? Call `/maintenance/courier-sync?key=YOURKEY`
from any free uptime/cron-job service every 15 minutes (see "cPanel / no terminal"). Users can always press "Refresh status" on a shipment.

## Storage, queue and image processing (Phase 1)
- Uploads, derivatives and import files live on the private disk under `tenants/{id}/…` (`CATALOG_DISK`, `IMPORT_DISK`).
  Back this directory up; it is part of tenant data. Nothing under it is web-accessible; images are served by `/app/media/{id}/{size}`.
- Image and import jobs run on the queue: `php artisan queue:work --tries=2 --timeout=300 --memory=512`.
  The default `QUEUE_CONNECTION=database` works; with `sync` uploads process inside the request (fine locally, not in production).
- PHP needs `gd` with WebP; `imagick` with HEIC is optional (iPhone photos; otherwise users are asked to export JPEG).
- Limits (env): `CATALOG_MAX_IMAGES`, `CATALOG_MAX_UPLOAD_KB`, `CATALOG_STORAGE_QUOTA_MB`, `IMPORT_MAX_ROWS`, `IMPORT_MAX_FILE_KB`.
  Set nginx `client_max_body_size` and PHP `upload_max_filesize`/`post_max_size` above these (e.g. 12M).

## Scheduler and queue
```
* * * * * cd /var/www/fcommerce && php artisan schedule:run >> /dev/null 2>&1
```
Supervised worker: `php artisan queue:work --tries=3 --backoff=10`.
No persistent worker available: cron `* * * * * php artisan queue:work --stop-when-empty --max-time=55`.

## Platform super admin
Granted only from the CLI and audited: `php artisan fc:make-super-admin user@example.com` (`--revoke` to remove).

## Service-worker kill switch (PWA-23)
If a faulty SW ships, replace `public/sw.js` with this and deploy. Clients fetch it on next launch (it is `no-cache`),
it deletes every cache and unregisters itself:
```js
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const k of await caches.keys()) await caches.delete(k);
  await self.registration.unregister();
  for (const c of await self.clients.matchAll({ type: 'window' })) c.navigate(c.url);
})()));
```
Then ship a fixed build; the next normal `npm run build` regenerates `sw.js`. Rehearse this in Phase 6.

## Environment notes
- Dev/test use SQLite; production is MySQL 8. The schema avoids SQLite-only features, but **migrations have not yet
  been run against MySQL in this repo's CI** — do that before pilot.
- E2E (`npm run test:e2e`) uses `.env.e2e` (throwaway key, own SQLite file) and `php artisan serve`.
  Run `npm run build` first. WebKit projects need `npx playwright install --with-deps webkit`.
- Tests: `php artisan test`, `npm test`, `npm run typecheck`, `npm run lint:sw`.

## Deploying in a sub-folder (e.g. https://www.sheracart.com/fcom-manager)

One value controls it: `APP_BASE_PATH`.

| | local / own domain | cPanel sub-folder |
|---|---|---|
| `.env` | `APP_BASE_PATH=` | `APP_BASE_PATH=/fcom-manager` and `APP_URL=https://www.sheracart.com/fcom-manager` |

What reads it: `config/deployment.php` (Blade + the generated `/manifest.webmanifest`), `vite.config.ts` (asset base and the
service-worker precache list, read at **build** time), and the browser (`<meta name="app-base">`, used by Inertia requests,
`/health`, the service-worker registration and scope). The service worker derives the base from its own scope.

Steps (no Node on the server):
1. Locally set `APP_BASE_PATH=/fcom-manager` in `.env`, then `npm run build`. The base is baked into `public/build` and `public/sw.js`,
   so a build made for one folder is wrong for another: rebuild when the folder changes.
2. Upload the app (split layout):
   - **Everything except `public/`** -> `/home/sheracar/Fcom-manager/` (app, vendor, bootstrap, config, storage, `.env` ...).
   - **Contents of `public/`** (`build/`, `icons/`, `sw.js`, `offline.*`, `favicon.ico`, `robots.txt`, `.htaccess`) -> `/home/sheracar/public_html/fcom-manager/`.
   - **`cpanel/index.php`** -> `/home/sheracar/public_html/fcom-manager/index.php` (replaces `public/index.php`; it points at the app folder
     and calls `usePublicPath` so Laravel finds `build/` and `offline.html` next to it).
   - Make `storage/` and `bootstrap/cache/` writable (755/775). Then `/fcom-manager/maintenance/storage-link?key=...` once.
3. On the server `.env`: `APP_BASE_PATH=/fcom-manager`, `APP_URL=...`, `APP_ENV=production`, `APP_DEBUG=false`; then open
   `/fcom-manager/maintenance/clear?key=...` to drop the config cache.
4. Users who installed the PWA from another URL must reinstall it (the manifest `id`/`scope` changed) and clear the old site data.
