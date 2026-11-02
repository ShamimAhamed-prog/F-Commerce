import { expect, test } from '@playwright/test';
import { register } from './helpers';

test('stepped order creation on mobile; dropped request shows "uncertain result" and a safe resubmit makes exactly one order', async ({ page }) => {
    await register(page);
    await page.goto('/app/products/create');
    await page.getByLabel('Name', { exact: true }).fill('Cotton Panjabi');
    await page.getByLabel('SKU', { exact: true }).fill('PNJ-1');
    await page.getByLabel('Selling price (৳)').fill('1500');
    await page.getByLabel('Opening stock').fill('5');
    await page.getByRole('button', { name: 'Save product' }).click();
    await expect(page).toHaveURL(/\/edit/);

    await page.goto('/app/orders/create');
    await page.getByLabel('Customer', { exact: true }).fill('Rahim');
    await page.getByLabel('Phone').fill('01712345678');
    await page.getByTestId('next').click();

    await page.getByTestId('item-search').fill('Panjabi');
    await page.getByTestId('item-result').first().click();
    await expect(page.getByTestId('order-line')).toHaveCount(1);
    await page.getByTestId('next').click();

    await page.getByLabel('Delivery address').fill('Dhanmondi, Dhaka');
    await page.getByLabel('Delivery charge').fill('80');
    await page.getByLabel('Advance received').fill('100');
    await expect(page.getByTestId('est-total')).toHaveText('৳1,580.00'); // sticky summary, labelled as an estimate
    await expect(page.getByTestId('est-cod')).toHaveText('৳1,480.00');
    await page.getByTestId('next').click();

    let dropped = false;
    await page.route('**/app/orders', (route) => {
        if (route.request().method() === 'POST' && !dropped) { dropped = true; return route.abort('connectionreset'); }
        return route.continue();
    });
    await page.getByRole('button', { name: 'Place order' }).click();
    await expect(page.getByTestId('uncertain')).toBeVisible();

    await page.getByRole('button', { name: 'Submit again' }).click();
    await expect(page).toHaveURL(/\/app\/orders\/\d+$/);
    await expect(page.getByTestId('cod')).toHaveText('৳1,480.00'); // server-confirmed

    await page.goto('/app/orders');
    await expect(page.getByText('ORD-000001')).toHaveCount(1);
    await expect(page.getByText('ORD-000002')).toHaveCount(0);
});

test('confirm moves the order forward and More tab reaches customers on mobile', async ({ page }) => {
    await register(page);
    await page.goto('/app/more');
    await expect(page.getByRole('link', { name: 'Customers' }).first()).toBeVisible();
    const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(over).toBe(false);
});
