import { expect, test } from '@playwright/test';
import { register } from './helpers';

test('connect Steadfast, send a confirmed order to the courier, see tracking, refresh status', async ({ page }) => {
    await page.request.post('http://127.0.0.1:8130/__reset'); // fresh courier account state for this test
    await register(page);

    // product + order
    await page.goto('/app/products/create');
    await page.getByLabel('Name', { exact: true }).fill('Cotton Panjabi');
    await page.getByLabel('SKU', { exact: true }).fill('PNJ-1');
    await page.getByLabel('Selling price (৳)').fill('1500');
    await page.getByLabel('Opening stock').fill('5');
    await page.getByRole('button', { name: 'Save product' }).click();
    await expect(page).toHaveURL(/\/edit/);

    await page.goto('/app/orders/create');
    await page.getByLabel('Customer', { exact: true }).fill('Rahim Uddin');
    await page.getByLabel('Phone').fill('+880 1712-345678');
    await page.getByTestId('next').click();
    await page.getByTestId('item-search').fill('Panjabi');
    await page.getByTestId('item-result').first().click();
    await page.getByTestId('next').click();
    await page.getByLabel('Delivery address').fill('House 5, Dhanmondi');
    await page.getByLabel('Delivery charge').fill('80');
    await page.getByTestId('next').click();
    await page.getByRole('button', { name: 'Place order' }).click();
    await expect(page).toHaveURL(/\/app\/orders\/\d+$/);
    const orderUrl = page.url();

    // before it is confirmed, there is nothing to send
    await expect(page.getByTestId('courier-section')).toContainText('Confirm the order');
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();

    // no courier connected yet -> guided to set one up
    await expect(page.getByTestId('courier-section')).toContainText('No courier account is connected');
    await page.goto('/app/settings/couriers');
    await page.getByLabel('API key').fill('e2e-key');
    await page.getByLabel('Secret key').fill('e2e-secret');
    await page.getByRole('button', { name: 'Save & test connection' }).click();
    await expect(page.getByText('Connected', { exact: true }).first()).toBeVisible();

    // send
    await page.goto(orderUrl);
    page.once('dialog', (d) => d.accept());
    await page.locator('[data-courier=steadfast]').click();
    await expect(page.getByTestId('tracking-code')).toHaveText(/^TRK\d+$/);
    await expect(page.getByTestId('shipment-card')).toContainText('Booked');

    // a booked order cannot be sent twice
    await expect(page.locator('[data-courier=steadfast]')).toHaveCount(0);

    // courier status moves; refresh shows it. "awaiting confirmation" is shown but never acted on.
    await page.request.post('http://127.0.0.1:8130/__set/ORD-000001/delivered_approval_pending');
    await page.locator('[data-action=refresh]').click();
    await expect(page.getByTestId('shipment-card')).toContainText('Delivered (awaiting confirmation)');

    // shipments list shows it
    await page.goto('/app/shipments');
    await expect(page.getByTestId('tracking-code')).toBeVisible();
});

test('shipments pages fit the viewport', async ({ page }) => {
    await register(page);
    await page.goto('/app/shipments');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBe(false);
});
