import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        tailwindcss(),
        // The SW is built from resources/js/sw/sw.ts. We do NOT use the plugin's
        // generated manifest or registration: the manifest is a hand-written static
        // file in /public and registration is done by useServiceWorkerUpdate (PWA-20).
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/sw',
            filename: 'sw.ts',
            outDir: 'public',
            injectRegister: false,
            manifest: false,
            injectManifest: {
                // Only the compiled app shell; no HTML (it is server-rendered per user).
                globDirectory: 'public/build',
                globPatterns: ['assets/**/*.{js,css,woff2}'],
                modifyURLPrefix: { '': '/build/' },
                additionalManifestEntries: ['/offline.html', '/offline.css', '/offline.js'].map((url) => ({ url, revision: String(Date.now()) })),
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
            devOptions: { enabled: false },
        }),
    ],
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        globals: true,
    },
});
