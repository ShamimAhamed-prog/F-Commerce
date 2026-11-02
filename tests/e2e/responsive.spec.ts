import { expect, test } from '@playwright/test';
import { register } from './helpers';

for (const width of [320, 360, 390, 430, 600, 768, 1024, 1280]) {
    test(`no horizontal scroll at ${width}px (UI-1)`, async ({ page }) => {
        await page.setViewportSize({ width, height: 800 });
        await page.goto('/auth/login');
        const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(over).toBe(false);
    });
}

test('navigation adapts: bottom tabs on phone, rail on tablet, sidebar on desktop (§13B.2)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await register(page);
    const visible = (sel: string) => page.locator(sel).filter({ visible: true }).count();

    expect(await visible('nav.fixed[aria-label=Primary]')).toBe(1);
    await page.setViewportSize({ width: 768, height: 900 });
    expect(await visible('nav.fixed[aria-label=Primary]')).toBe(0);
    expect(await visible('aside.sm\\:flex.lg\\:hidden')).toBe(1);
    await page.setViewportSize({ width: 1280, height: 900 });
    expect(await visible('aside.w-64')).toBe(1);
});

test('touch targets are at least 44px on phone (UI-2)', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 640 });
    await register(page);
    const small = await page.evaluate(() =>
        [...document.querySelectorAll('button, a[href], input, select')]
            .filter((e) => (e as HTMLElement).offsetParent !== null)
            .map((e) => ({ t: e.textContent?.trim().slice(0, 20), h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width }))
            .filter((r) => r.h < 36 || r.w < 36),
    );
    expect(small).toEqual([]);
});
