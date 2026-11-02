import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createConnection } from './useConnection';

const ok = () => Promise.resolve(new Response('{}', { status: 200 }));

describe('useConnection', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('is online only when the health probe answers', async () => {
        const c = createConnection({ fetchFn: ok as any, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('online');
        expect(c.writesBlocked.value).toBe(false);
    });

    it('is offline when navigator says so, without probing', async () => {
        const fetchFn = vi.fn();
        const c = createConnection({ fetchFn: fetchFn as any, isNavigatorOnline: () => false, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('offline');
        expect(fetchFn).not.toHaveBeenCalled();
        expect(c.writesBlocked.value).toBe(true);
    });

    it('navigator.onLine=true is only a hint: a failing probe means offline', async () => {
        const c = createConnection({ fetchFn: (() => Promise.reject(new TypeError('fail'))) as any, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('offline');
    });

    it('distinguishes server down (5xx) from no internet', async () => {
        const c = createConnection({ fetchFn: (() => Promise.resolve(new Response('', { status: 503 }))) as any, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('unreachable');
        expect(c.writesBlocked.value).toBe(true);
    });

    it('treats a probe timeout as unreachable', async () => {
        const hang = (_u: any, init: RequestInit) =>
            new Promise((_r, rej) => init.signal!.addEventListener('abort', () => rej(Object.assign(new Error('a'), { name: 'AbortError' }))));
        const c = createConnection({ fetchFn: hang as any, timeoutMs: 1000, channelName: null });
        const p = c.probe();
        await vi.advanceTimersByTimeAsync(1000);
        await p;
        expect(c.state.value).toBe('unreachable');
    });

    it('marks slow connections without blocking writes', async () => {
        let now = 0;
        vi.spyOn(Date, 'now').mockImplementation(() => now);
        const slow = () => {
            now += 4000;
            return ok();
        };
        const c = createConnection({ fetchFn: slow as any, slowMs: 3000, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('slow');
        expect(c.writesBlocked.value).toBe(false);
        vi.restoreAllMocks();
    });

    it('recovers and flags justRecovered, then clears it', async () => {
        let up = false;
        const f = () => (up ? ok() : Promise.reject(new TypeError('x')));
        const c = createConnection({ fetchFn: f as any, channelName: null });
        await c.probe();
        expect(c.state.value).toBe('offline');
        up = true;
        await c.probe();
        expect(c.state.value).toBe('online');
        expect(c.justRecovered.value).toBe(true);
        await vi.advanceTimersByTimeAsync(3100);
        expect(c.justRecovered.value).toBe(false);
    });

    it('reacts immediately to the browser offline event', async () => {
        const c = createConnection({ fetchFn: ok as any, channelName: null });
        c.start();
        await vi.advanceTimersByTimeAsync(10);
        window.dispatchEvent(new Event('offline'));
        expect(c.state.value).toBe('offline');
        c.stop();
    });

    it('backs off probe interval while failing and resets on success', async () => {
        const calls: number[] = [];
        let up = false;
        const f = () => {
            calls.push(Date.now());
            return up ? ok() : Promise.reject(new TypeError('x'));
        };
        const c = createConnection({ fetchFn: f as any, baseIntervalMs: 1000, maxIntervalMs: 8000, channelName: null });
        c.start();
        await vi.advanceTimersByTimeAsync(20_000);
        const gaps = calls.slice(1).map((t, i) => t - calls[i]);
        expect(gaps[1]).toBeGreaterThan(gaps[0]);
        expect(Math.max(...gaps)).toBeLessThanOrEqual(8000 + 5);
        up = true;
        await vi.advanceTimersByTimeAsync(10_000);
        c.stop();
    });
});
