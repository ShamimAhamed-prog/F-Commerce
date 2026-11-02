import { expect, test } from '@playwright/test';
import { openUserMenu, register, swReady } from './helpers';

test('offline: banner, disabled writes with reason, no non-GET requests (OFF-1, OFF-6)', async ({ page, context }) => {
    await register(page);
    await swReady(page);

    const nonGet: string[] = [];
    page.on('request', (r) => r.method() !== 'GET' && nonGet.push(`${r.method()} ${r.url()}`));

    await context.setOffline(true);
    await expect(page.getByTestId('connection-banner').first()).toContainText("You're offline");

    await openUserMenu(page);
    const logout = page.getByRole('button', { name: 'Sign out' });
    await expect(logout).toBeDisabled();
    await expect(page.getByText('Needs internet').first()).toBeVisible();
    await logout.click({ force: true }).catch(() => undefined);
    expect(nonGet).toEqual([]);

    await context.setOffline(false);
    await expect(page.getByText('Back online')).toBeVisible({ timeout: 35_000 });
});

test('offline navigation shows the static fallback, never a stale authenticated page', async ({ page, context }) => {
    await register(page);
    await swReady(page);
    await context.setOffline(true);
    await page.goto('/app/workspaces').catch(() => undefined);
    await expect(page.getByRole('heading', { name: /You're offline/ })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E User');
    await context.setOffline(false);
});

test('offline page is served from precache with styles', async ({ page, context }) => {
    await register(page);
    await swReady(page);
    await context.setOffline(true);
    await page.goto('/app/dashboard').catch(() => undefined);
    const bg = await page.evaluate(() => getComputedStyle(document.body).display);
    expect(bg).toBe('grid'); // offline.css applied
    await context.setOffline(false);
});
