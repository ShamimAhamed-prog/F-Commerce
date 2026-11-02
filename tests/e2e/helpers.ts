import { expect, type Page } from '@playwright/test';

let counter = 0;

export async function register(page: Page, workspace = 'E2E Shop') {
    const email = `e2e${Date.now()}${counter++}@example.com`;
    await page.goto('/auth/register');
    await page.getByLabel('Your name').fill('E2E User');
    await page.getByLabel('Business / workspace name').fill(workspace);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('secret123');
    await page.getByLabel('Confirm password').fill('secret123');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/app\/dashboard/);
    return email;
}

/** Wait until the service worker controls the page. */
export async function swReady(page: Page) {
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    await page.reload();
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
}

/** Sign-out lives in the avatar menu. Opens it and (optionally) clicks Sign out. */
export async function openUserMenu(page: Page) {
    if (!(await page.getByRole('button', { name: 'Sign out' }).isVisible())) await page.getByRole('button', { name: 'E2E User' }).click();
}

export async function signOut(page: Page) {
    await openUserMenu(page);
    await page.getByRole('button', { name: 'Sign out' }).click();
}
