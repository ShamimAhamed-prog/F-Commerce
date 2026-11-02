/// <reference lib="webworker" />
/**
 * Service worker (spec §13A.5). DEFAULT DENY.
 *
 * Cached: hashed build assets + /offline.html (precache), static icons.
 * Never cached: Inertia/JSON/XHR, any non-GET, auth, billing, courier, payment,
 * uploads, authenticated pages. No Background Sync / retry queue exists (OFF-7).
 */
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope;

// Sub-folder deploys: the SW is registered with scope `${BASE}/`, so the base is simply its own scope path.
const BASE = new URL(self.registration.scope).pathname.replace(/\/+$/, '');
const OFFLINE_URL = `${BASE}/offline.html`;

// Build assets (injected) — immutable, versioned by hash.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// Static icons: cache-first with expiration (public, tenant-agnostic).
registerRoute(
    ({ url, request }) => request.method === 'GET' && url.origin === self.location.origin && url.pathname.startsWith(`${BASE}/icons/`),
    new CacheFirst({
        cacheName: 'static-icons-v1',
        plugins: [new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 30 * 24 * 3600 })],
    }),
);

// Product image THUMB and CARD derivatives only (§13A.6): lets lists show thumbnails on weak networks
// without becoming an offline data store. Detail-size images are never cached. Capped, and purged on
// logout / tenant switch / session expiry via PURGE_CACHES (cache name does not start with workbox-precache).
registerRoute(
    ({ url, request }) => request.method === 'GET' && url.origin === self.location.origin && /^\/app\/media\/\d+\/(thumb|card)$/.test(url.pathname.slice(BASE.length)),
    new StaleWhileRevalidate({
        cacheName: 'product-thumbs-v1',
        plugins: [
            new CacheableResponsePlugin({ statuses: [200] }), // never cache 403/404/redirects-to-login
            new ExpirationPlugin({ maxEntries: 100, maxAgeSeconds: 7 * 24 * 3600, purgeOnQuotaError: true }),
        ],
    }),
);

// Navigations: NETWORK-ONLY, falling back to the static offline page only when
// the network itself fails. A cached authenticated page is never served as live.
registerRoute(
    ({ request }) => request.mode === 'navigate',
    async ({ request }) => {
        try {
            return await fetch(request);
        } catch {
            const offline = await matchPrecache(OFFLINE_URL);
            if (!offline) return Response.error();
            // The page is shown for ANY failed navigation, so its relative links must resolve against the app root.
            const html = (await offline.text()).replace('<head>', `<head><base href="${BASE}/">`);
            return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        }
    },
);

// Everything else (JSON, XHR, POST/PUT/PATCH/DELETE, webhooks, payments, couriers)
// matches no route and goes straight to the network.

self.addEventListener('message', (event) => {
    const type = event.data?.type;
    if (type === 'SKIP_WAITING') {
        // Sent only after the user accepts the update and nothing is in progress (PWA-20).
        void self.skipWaiting();
    } else if (type === 'PURGE_CACHES') {
        // SEC-P4: drop every runtime cache; keep the public precache.
        event.waitUntil(
            caches.keys().then((keys) =>
                Promise.all(keys.filter((k) => !k.startsWith('workbox-precache')).map((k) => caches.delete(k))),
            ),
        );
    }
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});
