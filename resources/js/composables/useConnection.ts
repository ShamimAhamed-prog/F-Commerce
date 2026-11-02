import { computed, readonly, ref } from 'vue';

/**
 * Connection state machine (OFF-1..OFF-5).
 * `navigator.onLine` is only a hint; "online" means /health answered.
 */
export type ConnectionState = 'online' | 'offline' | 'slow' | 'unreachable';

export interface ConnectionOptions {
    fetchFn?: typeof fetch;
    probeUrl?: string;
    baseIntervalMs?: number;
    maxIntervalMs?: number;
    timeoutMs?: number;
    slowMs?: number;
    isNavigatorOnline?: () => boolean;
    isVisible?: () => boolean;
    channelName?: string | null;
}

export function createConnection(opts: ConnectionOptions = {}) {
    const fetchFn = opts.fetchFn ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
    const probeUrl = opts.probeUrl ?? '/health';
    const base = opts.baseIntervalMs ?? 20_000;
    const max = opts.maxIntervalMs ?? 120_000;
    const timeoutMs = opts.timeoutMs ?? 6_000;
    const slowMs = opts.slowMs ?? 3_000;
    const navOnline = opts.isNavigatorOnline ?? (() => (typeof navigator === 'undefined' ? true : navigator.onLine));
    const visible = opts.isVisible ?? (() => (typeof document === 'undefined' ? true : document.visibilityState === 'visible'));

    const state = ref<ConnectionState>('online');
    const justRecovered = ref(false);
    const saveData = ref(false);
    let interval = base;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let started = false;
    let channel: BroadcastChannel | null = null;
    let recoveredTimer: ReturnType<typeof setTimeout> | null = null;

    function set(next: ConnectionState, broadcast = true) {
        const prev = state.value;
        if (prev === next) return;
        state.value = next;
        const wasDown = prev === 'offline' || prev === 'unreachable';
        if (wasDown && (next === 'online' || next === 'slow')) {
            justRecovered.value = true;
            if (recoveredTimer) clearTimeout(recoveredTimer);
            recoveredTimer = setTimeout(() => (justRecovered.value = false), 3_000);
        }
        if (broadcast) channel?.postMessage({ state: next });
    }

    function networkHint(): boolean {
        const c = (typeof navigator !== 'undefined' ? (navigator as any).connection : null) as
            | { effectiveType?: string; saveData?: boolean }
            | null;
        saveData.value = !!c?.saveData;
        return !!c && ['slow-2g', '2g'].includes(c.effectiveType ?? '');
    }

    async function probe(): Promise<ConnectionState> {
        if (!navOnline()) {
            set('offline');
            interval = Math.min(interval * 2, max);
            return state.value;
        }

        const ctrl = new AbortController();
        const timeout = setTimeout(() => ctrl.abort(), timeoutMs);
        const started = Date.now();
        try {
            const res = await fetchFn(probeUrl, { cache: 'no-store', signal: ctrl.signal, credentials: 'omit' });
            if (res.status >= 500) {
                set('unreachable'); // server answered with an error: maintenance / outage
                interval = Math.min(interval * 2, max);
            } else {
                const slow = Date.now() - started > slowMs || networkHint();
                set(slow ? 'slow' : 'online');
                interval = base;
            }
        } catch (e) {
            const timedOut = (e as Error)?.name === 'AbortError';
            set(timedOut ? 'unreachable' : 'offline');
            interval = Math.min(interval * 2, max);
        } finally {
            clearTimeout(timeout);
        }
        return state.value;
    }

    function schedule() {
        if (!started) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(async () => {
            if (visible()) await probe();
            schedule();
        }, interval);
    }

    /** Call after any failed request so the indicator reacts immediately. */
    async function reportFailure() {
        await probe();
        schedule();
    }

    function start() {
        if (started) return;
        started = true;
        if (typeof window !== 'undefined') {
            window.addEventListener('online', onOnline);
            window.addEventListener('offline', onOffline);
            document.addEventListener('visibilitychange', onVisible);
            if (opts.channelName !== null && 'BroadcastChannel' in window) {
                channel = new BroadcastChannel(opts.channelName ?? 'fc-connection');
                channel.onmessage = (e) => {
                    if (e.data?.state) set(e.data.state, false);
                };
            }
        }
        if (!navOnline()) set('offline');
        void probe().then(schedule);
    }

    function stop() {
        started = false;
        if (timer) clearTimeout(timer);
        if (typeof window !== 'undefined') {
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
            document.removeEventListener('visibilitychange', onVisible);
        }
        channel?.close();
        channel = null;
    }

    function onOnline() {
        interval = base;
        void probe().then(schedule);
    }
    function onOffline() {
        set('offline'); // immediate on the event (OFF acceptance: no 30s wait)
    }
    function onVisible() {
        if (visible()) void probe().then(schedule);
    }

    return {
        state: readonly(state),
        justRecovered: readonly(justRecovered),
        saveData: readonly(saveData),
        /** Writes are blocked when the server is not known to be reachable (OFF-6). */
        writesBlocked: computed(() => state.value === 'offline' || state.value === 'unreachable'),
        probe,
        reportFailure,
        start,
        stop,
    };
}

let singleton: ReturnType<typeof createConnection> | null = null;

export function useConnection() {
    return (singleton ??= createConnection());
}
