import { ref, watch } from 'vue';
import { BASE } from '@/lib/base';
import { isBusy } from './useBusy';

/**
 * Service worker registration + safe update flow (PWA-19, PWA-20).
 * A waiting worker is only activated after the user accepts AND nothing is
 * in progress (dirty form / active upload); otherwise it is deferred.
 */
const updateAvailable = ref(false);
const deferred = ref(false);
let wb: import('workbox-window').Workbox | null = null;
let registered = false;

export async function registerServiceWorker(): Promise<void> {
    // SEC-P2: HTTPS (or localhost) only.
    if (registered || typeof window === 'undefined' || !('serviceWorker' in navigator) || !window.isSecureContext) return;
    registered = true;

    const { Workbox } = await import('workbox-window');
    wb = new Workbox(`${BASE}/sw.js`, { scope: `${BASE}/` });

    wb.addEventListener('waiting', () => (updateAvailable.value = true));
    wb.addEventListener('controlling', (e) => {
        // Reload only when this tab asked for the update (isUpdate) to avoid reload loops.
        if (e.isUpdate && applying) window.location.reload();
    });

    const reg = await wb.register();
    const check = () => reg?.update().catch(() => undefined);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && check());
    setInterval(check, 30 * 60 * 1000);
}

let applying = false;

export function useServiceWorkerUpdate() {
    function apply() {
        if (!wb) return;
        if (isBusy.value) {
            deferred.value = true; // retried by the watcher once idle
            return;
        }
        applying = true;
        wb.messageSkipWaiting();
    }

    watch(isBusy, (busy) => {
        if (!busy && deferred.value) {
            deferred.value = false;
            apply();
        }
    });

    return { updateAvailable, deferred, apply, dismiss: () => (updateAvailable.value = false) };
}
