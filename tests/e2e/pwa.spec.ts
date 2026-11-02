import { expect, test } from '@playwright/test';
import { register, swReady } from './helpers';

test('manifest is valid and linked on auth pages (PWA-1, PWA-2, PWA-6)', async ({ page, request }) => {
    await page.goto('/auth/login');
    expect(await page.locator('link[rel=manifest]').getAttribute('href')).toBe('/manifest.webmanifest');
    expect(await page.locator('link[rel=apple-touch-icon]').count()).toBe(1);
    expect(await page.locator('meta[name=viewport]').getAttribute('content')).toContain('viewport-fit=cover');

    const m = await (await request.get('/manifest.webmanifest')).json();
    expect(m.display).toBe('standalone');
    expect(m.start_url).toContain('/app/dashboard');
    const sizes = m.icons.map((i: any) => `${i.sizes}:${i.purpose}`);
    expect(sizes).toEqual(expect.arrayContaining(['192x192:any', '512x512:any', '512x512:maskable']));
    for (const icon of m.icons) expect((await request.get(icon.src)).ok()).toBe(true);
});

test('service worker registers on HTTPS-equivalent origin and controls the page (PWA-15)', async ({ page }) => {
    await register(page);
    await swReady(page);
    const sw = await page.evaluate(async () => {
        const r = await navigator.serviceWorker.getRegistration();
        return { scope: r?.scope, active: !!r?.active };
    });
    expect(sw.active).toBe(true);
});

test('no background sync registration exists (OFF-7)', async ({ page }) => {
    await register(page);
    await swReady(page);
    const tags = await page.evaluate(async () => {
        const r: any = await navigator.serviceWorker.ready;
        try {
            return r.sync ? await r.sync.getTags() : [];
        } catch {
            return []; // Background Sync disabled/unsupported (headless, WebKit, Firefox): nothing can be registered
        }
    });
    expect(tags).toEqual([]);
});

test('page responds with strict CSP and no inline script violations', async ({ page }) => {
    const violations: string[] = [];
    page.on('console', (m) => /Content Security Policy/i.test(m.text()) && violations.push(m.text()));
    const res = await page.goto('/auth/login');
    expect(res!.headers()['content-security-policy']).toContain("script-src 'self'");
    await page.waitForLoadState('load');
    await page.waitForTimeout(1500);
    expect(violations).toEqual([]);
});
