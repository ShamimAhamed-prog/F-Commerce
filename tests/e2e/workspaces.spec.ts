import { expect, test } from '@playwright/test';
import { openUserMenu, register } from './helpers';

test('after login with several workspaces, tapping a workspace anywhere on its card opens the dashboard', async ({ page }) => {
    const email = await register(page, 'Shop One');
    await page.goto('/app/workspaces');
    await page.getByLabel('Workspace name').fill('Shop Two');
    await page.getByRole('button', { name: 'Create workspace' }).click();
    await expect(page).toHaveURL(/\/app\/dashboard/);

    await openUserMenu(page);
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/auth\/login/);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('secret123');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/app\/workspaces/); // several workspaces, none selected: the picker
    await expect(page.getByTestId('workspace-card')).toHaveCount(2);

    // Click the workspace NAME (not a special button): the whole card must work.
    await page.getByTestId('workspace-card').filter({ hasText: 'Shop One' }).getByText('Shop One').click();
    await expect(page).toHaveURL(/\/app\/dashboard/);
    await expect(page.getByRole('button', { name: /Shop One/ }).first()).toBeVisible();
});
