/**
 * Client data purge (SEC-P4, SEC-P5). Called on logout, tenant switch and
 * session expiry. Removes everything tied to the user/tenant:
 *  - Cache Storage (runtime caches, incl. thumbnails)
 *  - sessionStorage / localStorage
 *  - IndexedDB databases (drafts)
 *  - tells the service worker to drop its caches too
 * Drafts survive a session-expiry purge only (OFF-12), via keepDrafts.
 * Note: the Workbox precache holds only public build assets, so it is kept.
 */
export interface PurgeOptions {
    keepDrafts?: boolean;
}

export const DRAFT_PREFIX = 'fc:draft:';
/** Non-personal UI preferences (install-banner cooldown, theme) survive purges. */
export const UI_PREFIX = 'fc:ui:';

export async function purgeClientData(opts: PurgeOptions = {}): Promise<void> {
    const tasks: Promise<unknown>[] = [];

    try {
        if (typeof caches !== 'undefined') {
            tasks.push(
                caches.keys().then((keys) =>
                    // The precache holds only public static build assets; keep it so the app shell still works.
                    Promise.all(keys.filter((k) => !k.startsWith('workbox-precache')).map((k) => caches.delete(k))),
                ),
            );
        }
    } catch {
        /* storage may be blocked */
    }

    try {
        navigator.serviceWorker?.controller?.postMessage({ type: 'PURGE_CACHES' });
    } catch {
        /* ignore */
    }

    try {
        for (const store of [window.sessionStorage, window.localStorage]) {
            for (const key of Object.keys(store)) {
                if (key.startsWith(UI_PREFIX)) continue;
                if (opts.keepDrafts && key.startsWith(DRAFT_PREFIX)) continue;
                store.removeItem(key);
            }
        }
    } catch {
        /* ignore */
    }

    if (!opts.keepDrafts) {
        try {
            if (typeof indexedDB !== 'undefined') {
                const dbs = (await indexedDB.databases?.()) ?? [];
                for (const db of dbs) {
                    if (db.name) tasks.push(deleteDb(db.name));
                }
            }
        } catch {
            /* ignore */
        }
    }

    await Promise.allSettled(tasks);
}

function deleteDb(name: string): Promise<void> {
    return new Promise((resolve) => {
        const req = indexedDB.deleteDatabase(name);
        req.onsuccess = req.onerror = req.onblocked = () => resolve();
    });
}
