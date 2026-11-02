import { expect, test } from '@playwright/test';
import { openUserMenu, register, signOut } from './helpers';

const bg = (page: any) => page.evaluate(() => getComputedStyle(document.body).backgroundColor);

const small = (page: any) => (page.viewportSize()?.width ?? 1280) < 640;

test('theme toggle: dark/light/system, persists across reload and logout, and the page uses the dark palette', async ({ page }) => {
    await register(page);
    await page.goto('/app/dashboard');
    const light = await bg(page);
    if (small(page)) await openUserMenu(page); // on phones the toggle lives in the user menu

    await page.locator('[data-theme-option=dark]:visible').click();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('dark');
    await expect.poll(() => bg(page)).not.toBe(light);
    await page.reload();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('dark'); // applied before first paint

    await signOut(page);
    await expect(page).toHaveURL(/\/auth\/login/);
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('dark'); // UI preference survives the logout purge

    await page.locator('[data-theme-option=light]:visible').click();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe('light');
    await page.locator('[data-theme-option=system]:visible').click();
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBeUndefined();
});

test('dashboard renders for a new workspace, range chips work, no horizontal scroll', async ({ page }) => {
    await register(page);
    await expect(page.getByRole('heading', { name: /Good (morning|afternoon|evening)/ })).toBeVisible();
    await expect(page.getByText('Gross sales')).toBeVisible();
    await page.locator('[data-range="7d"]').click();
    await expect(page.locator('[data-range="7d"]')).toHaveAttribute('aria-pressed', 'true');
    const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(over).toBe(false);
});

test('accounting: capital in, expense out, balances and reports agree', async ({ page }) => {
    await register(page);
    await page.goto('/app/accounting/equity');
    await page.getByTestId('add-capital').click();
    await page.getByLabel('Amount (৳)').fill('10000');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('+৳10,000.00')).toBeVisible();

    await page.goto('/app/accounting/expenses');
    await page.getByTestId('add-expense').click();
    await page.getByLabel('Amount (৳)').fill('2500');
    await page.getByLabel('Vendor').fill('Meta');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByTestId('expense-row')).toHaveCount(1);

    await page.goto('/app/accounting');
    await expect(page.getByText('৳7,500.00').first()).toBeVisible(); // cash: 10,000 - 2,500

    await page.goto('/app/reports/trial-balance');
    await expect(page.getByText('Balanced')).toBeVisible();
    await page.goto('/app/reports/profit-loss');
    await expect(page.getByText('-৳2,500.00').first()).toBeVisible();   // capital is not income; the expense is a loss
    await page.goto('/app/reports/balance-sheet');
    await expect(page.getByText('Balanced')).toBeVisible();
    await page.goto('/app/accounting/journal');
    await expect(page.getByTestId('journal-entry')).toHaveCount(2);
});

test('accounting and report screens fit the viewport', async ({ page }) => {
    await register(page);
    for (const url of ['/app/accounting', '/app/accounting/expenses', '/app/accounting/cod', '/app/accounting/equity', '/app/accounting/journal', '/app/accounting/accounts', '/app/reports/profit-loss', '/app/reports/trial-balance', '/app/reports/balance-sheet', '/app/reports/cash-flow', '/app/reports/ledger', '/app/settings', '/app/more']) {
        await page.goto(url);
        const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(over, url).toBe(false);
    }
});

test('loans are liabilities; receipts attach to expenses; reports export CSV', async ({ page }) => {
    await register(page);
    await page.goto('/app/accounting/loans');
    await page.getByTestId('add-loan').click();
    await page.getByLabel('Lender').fill('City Bank');
    await page.getByLabel('Amount (৳)').fill('50000');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByTestId('loan-row')).toContainText('City Bank');
    await expect(page.getByTestId('loan-row')).toContainText('৳50,000.00');

    await page.goto('/app/reports/profit-loss'); // borrowed money is not revenue
    await expect(page.getByText('৳0.00').first()).toBeVisible();
    await page.goto('/app/reports/balance-sheet');
    await expect(page.getByText('Balanced')).toBeVisible();

    await page.goto('/app/accounting/expenses');
    await page.getByTestId('add-expense').click();
    await page.getByLabel('Amount (৳)').fill('120');
    await page.getByTestId('receipt-input').setInputFiles('tests/e2e/fixtures/red.png');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByTestId('receipt-link')).toBeVisible();

    await page.goto('/app/reports/trial-balance');
    const href = await page.getByTestId('export-csv').getAttribute('href');
    expect(href).toContain('format=csv');
    const res = await page.request.get(href!);
    expect(res.headers()['content-type']).toContain('text/csv');
    expect(await res.text()).toContain('Code,Account,Type,Debit,Credit');
});
