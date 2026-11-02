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
