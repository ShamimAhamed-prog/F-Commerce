import { expect, test, type Page } from '@playwright/test';
import { register, signOut, swReady } from './helpers';

async function snapshotStores(page: Page) {
    return page.evaluate(async () => {
        const caches_ = await caches.keys();
        const entries: string[] = [];
        for (const k of caches_) {
            if (k.startsWith('workbox-precache')) continue; // public build assets only
            const c = await caches.open(k);
            entries.push(...(await c.keys()).map((r) => r.url));
        }
        const dbs = (await indexedDB.databases?.()) ?? [];
        return {
            runtimeCaches: caches_.filter((k) => !k.startsWith('workbox-precache')),
            entries,
            localStorage: Object.keys(localStorage).filter((k) => !k.startsWith('fc:ui:')),
            sessionStorage: Object.keys(sessionStorage),
            indexedDB: dbs.map((d) => d.name),
        };
    });
}

test('logout leaves no tenant data in any cache or storage (SEC-P4)', async ({ page }) => {
    await register(page, 'Tenant A Secret Shop');
    await swReady(page);
    await page.evaluate(() => {
        sessionStorage.setItem('fc:draft:1:1:order', JSON.stringify({ values: { customer: 'Secret' } }));
        localStorage.setItem('leak-probe', 'tenant-a');
    });

    await signOut(page);
    await expect(page).toHaveURL(/\/auth\/login/);

    const s = await snapshotStores(page);
    expect(s.runtimeCaches).toEqual([]);
    expect(s.entries).toEqual([]);
    expect(s.localStorage).toEqual([]);
    expect(s.sessionStorage).toEqual([]);

    // Going offline now must not reveal anything from tenant A.
    await page.context().setOffline(true);
    await page.goto('/app/dashboard').catch(() => undefined);
    await expect(page.locator('body')).not.toContainText('Tenant A Secret Shop');
    await page.context().setOffline(false);
});

test('switching tenant purges client data (SEC-P4)', async ({ page }) => {
    await register(page, 'Shop One');
    await page.goto('/app/workspaces');
    await page.getByLabel('Workspace name').fill('Shop Two');
    await page.getByRole('button', { name: 'Create workspace' }).click();
    await expect(page).toHaveURL(/dashboard/);

    await page.evaluate(() => localStorage.setItem('leak-probe', 'shop-two'));
    await page.getByRole('button', { name: /Shop Two/ }).click();
    await page.getByRole('menuitem', { name: 'Shop One' }).click();
    await expect(page.getByRole('button', { name: /Shop One/ })).toBeVisible();

    expect((await snapshotStores(page)).localStorage).toEqual([]);
});
