import { defineConfig, devices } from '@playwright/test';

const PORT = 8123;

// Device matrix (§14.4). iPhone/iPad need `npx playwright install webkit`.
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    globalSetup: './tests/e2e/global-setup.ts',
    use: { baseURL: `http://127.0.0.1:${PORT}`, trace: 'retain-on-failure' },
    projects: [
        { name: 'pixel', use: { ...devices['Pixel 7'] } },
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'iphone', use: { ...devices['iPhone 14'] } },
        { name: 'ipad', use: { ...devices['iPad (gen 7)'] } },
    ],
    webServer: {
        // Production-like: strict CSP, compiled assets, own SQLite file.
        command: `php artisan serve --env=e2e --host=127.0.0.1 --port=${PORT}`,
        url: `http://127.0.0.1:${PORT}/health`,
        reuseExistingServer: false,
        env: { PHP_CLI_SERVER_WORKERS: '4' },
    },
});
