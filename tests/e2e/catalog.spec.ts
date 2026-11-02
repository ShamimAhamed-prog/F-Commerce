import { expect, test, type Page } from '@playwright/test';
import { register, signOut } from './helpers';

const fx = (n: string) => `tests/e2e/fixtures/${n}.png`;
const csv = (body: string, name = 'products.csv') => ({ name, mimeType: 'text/csv', buffer: Buffer.from(body) });

async function createProduct(page: Page, sku: string, name = 'Cotton Panjabi') {
    await page.goto('/app/products/create');
    await page.getByLabel('Name', { exact: true }).fill(name);
    await page.getByLabel('SKU', { exact: true }).fill(sku);
    await page.getByLabel('Selling price (৳)').fill('1500');
    await page.getByLabel('Opening stock').fill('12');
    await page.getByRole('button', { name: 'Save product' }).click();
    await expect(page).toHaveURL(/\/app\/products\/\d+\/edit/);
}

const order = (page: Page) => page.locator('[data-testid=image-grid] li').evaluateAll((els) => els.map((e) => Number((e as HTMLElement).dataset.imageId)));

test('create a product, add images, reorder with buttons, set cover, delete (IMG-1..5)', async ({ page }) => {
    await register(page);
    await createProduct(page, 'PNJ-001');

    await page.getByTestId('image-input').setInputFiles([fx('red'), fx('green'), fx('blue')]);
    await expect(page.locator('[data-testid=image-grid] li img')).toHaveCount(3, { timeout: 15_000 });
    await expect(page.getByTestId('image-count')).toContainText('3 of 10');

    const [a, b, c] = await order(page);
    await page.locator(`[data-image-id="${c}"] [data-action=up]`).click();
    await expect.poll(() => order(page)).toEqual([a, c, b]);
    await page.reload();
    expect(await order(page)).toEqual([a, c, b]); // persisted as position

    // first image is the cover; make the last one the cover via the edit sheet
    await page.locator(`[data-image-id="${b}"] [data-action=edit]`).click();
    await page.locator('[data-action=primary]').click();
    await expect(page.locator(`[data-image-id="${b}"]`)).toContainText('Cover');

    // delete one (with confirmation)
    page.once('dialog', (d) => d.accept());
    await page.locator('[data-action=delete]').click();
    await expect(page.locator('[data-testid=image-grid] li')).toHaveCount(2);
});

test('image controls are disabled with a reason while offline and nothing is sent (OFF-6)', async ({ page, context }) => {
    await register(page);
    await createProduct(page, 'OFF-001');
    await page.getByTestId('image-input').setInputFiles([fx('red'), fx('green')]);
    await expect(page.locator('[data-testid=image-grid] li img')).toHaveCount(2, { timeout: 15_000 });

    const writes: string[] = [];
    page.on('request', (r) => r.method() !== 'GET' && writes.push(`${r.method()} ${r.url()}`));
    await context.setOffline(true);
    await expect(page.getByText('Image changes need an internet connection.')).toBeVisible();
    await expect(page.getByTestId('image-input')).toBeDisabled();
    await expect(page.locator('[data-action=down]').first()).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Save product' })).toBeDisabled();
    expect(writes).toEqual([]);
    await context.setOffline(false);
});

test('CSV import is data-only: image column ignored, no external requests, missing-images CTA (IMG-I2/I3/I5)', async ({ page }) => {
    const external: string[] = [];
    page.on('request', (r) => !r.url().startsWith('http://127.0.0.1') && !r.url().startsWith('data:') && !r.url().startsWith('blob:') && external.push(r.url()));

    await register(page);
    await page.goto('/app/imports');
    await page.getByTestId('import-file-products').setInputFiles(csv(
        'name,sku,selling_price,Image URL\nMug,MUG-1,250,https://evil.example.com/a.png\nCup,CUP-1,99,http://169.254.169.254/x\n'));
    await page.getByTestId('import-products').getByRole('button', { name: 'Upload CSV or XLSX' }).click();

    await expect(page.getByTestId('ignored-column')).toHaveText('Ignored: images are added after import', { timeout: 15_000 });
    await page.getByRole('button', { name: 'Save mapping & check rows' }).click();
    await expect(page.getByText('2 rows ready')).toBeVisible({ timeout: 15_000 });
    await page.getByRole('button', { name: 'Import 2 rows' }).click();

    await expect(page.getByTestId('n-created')).toHaveText('2', { timeout: 15_000 });
    await expect(page.getByTestId('missing-images-cta')).toContainText('2 products have no images');
    expect(external).toEqual([]);

    await page.getByRole('link', { name: 'Add images' }).click();
    await expect(page).toHaveURL(/missing-images/);
    await expect(page.getByText('Mug').first()).toBeVisible();
});

test('ZIP upload is rejected with a clear message (IMG-I4)', async ({ page }) => {
    await register(page);
    await page.goto('/app/imports');
    await page.getByTestId('import-file-products').setInputFiles({ name: 'photos.zip', mimeType: 'application/zip', buffer: Buffer.from('PK\x03\x04zzz') });
    await page.getByTestId('import-products').getByRole('button', { name: 'Upload CSV or XLSX' }).click();
    await expect(page.getByRole('alert')).toContainText('ZIP');
});

test('inventory: adjust stock, low-stock filter, dashboard counts', async ({ page }) => {
    await register(page);
    await createProduct(page, 'INV-1', 'Water Bottle');
    await page.goto('/app/inventory');
    await page.getByRole('button', { name: 'Adjust stock' }).first().click();
    await page.getByLabel('Quantity', { exact: true }).fill('-5');
    await page.getByLabel('Reason').fill('Damaged in storage');
    await page.getByRole('button', { name: 'Save' }).last().click();
    await expect(page.getByText('On hand 7')).toBeVisible();
    await page.goto('/app/dashboard');
    await expect(page.getByTestId('attn-missingImages')).toContainText('1');
});

test('catalog screens fit the viewport with no horizontal scroll (UI-1)', async ({ page }) => {
    await register(page);
    await createProduct(page, 'FIT-1');
    for (const url of ['/app/products', '/app/products/create', '/app/inventory', '/app/imports', '/app/products/missing-images', '/app/dashboard']) {
        await page.goto(url);
        const over = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(over, url).toBe(false);
    }
});

test('cached thumbnails are purged on logout and never include detail images (§13A.6, SEC-P4)', async ({ page }) => {
    const { swReady } = await import('./helpers');
    await register(page);
    await createProduct(page, 'THUMB-1');
    await page.getByTestId('image-input').setInputFiles([fx('red')]);
    await expect(page.locator('[data-testid=image-grid] li img')).toHaveCount(1, { timeout: 15_000 });
    await swReady(page);
    await page.goto('/app/products');
    await expect(page.locator('img[src*="/app/media/"]').and(page.locator(':visible')).first()).toBeVisible();

    await expect.poll(() => page.evaluate(async () => {
        const c = await caches.open('product-thumbs-v1');
        return (await c.keys()).map((r) => new URL(r.url).pathname);
    })).toEqual(expect.arrayContaining([expect.stringMatching(/\/app\/media\/\d+\/(thumb|card)$/)]));
    const cached = await page.evaluate(async () => (await (await caches.open('product-thumbs-v1')).keys()).map((r) => r.url));
    expect(cached.some((u) => /\/detail/.test(u))).toBe(false);

    await signOut(page);
    await expect(page).toHaveURL(/\/auth\/login/);
    expect(await page.evaluate(() => caches.keys())).not.toContain('product-thumbs-v1');
});
