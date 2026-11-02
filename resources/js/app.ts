import '../css/app.css';
import { createInertiaApp, http, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { i18n } from './i18n';
import { useConnection } from './composables/useConnection';
import { installAnchorRewriter, withBase, withBaseUrl } from './lib/base';
import { purgeClientData } from './lib/purge';
import { registerServiceWorker } from './composables/useServiceWorkerUpdate';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    nonce: document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content || undefined,
    title: (title) => (title ? `${title} - F-Commerce` : 'F-Commerce'),
    resolve: async (name) => {
        const loader = pages[`./Pages/${name}.vue`];
        if (!loader) throw new Error(`Unknown page: ${name}`);
        return (await loader()) as any;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
});

// Sub-folder deploys: every Inertia request (Link, router.*, forms) gets the base path; no-op at the domain root.
http.onRequest((config: any) => ({ ...config, url: withBaseUrl(config.url) }));
installAnchorRewriter();

const connection = useConnection();
connection.start();

// A failed request is a signal to re-probe immediately (OFF-2).
router.on('networkError', () => void connection.reportFailure());

// SEC-P4 / OFF-12: session expiry (401/419) -> purge caches, KEEP unsent drafts, re-authenticate.
// A financial POST is never silently replayed (SEC-P7): we redirect, the user re-initiates.
router.on('httpException', (event) => {
    const status = (event.detail as any)?.response?.status;
    if (status === 401 || status === 419) {
        event.preventDefault();
        void purgeClientData({ keepDrafts: true }).finally(() => window.location.assign(withBase('/auth/login')));
    } else if (status >= 500) {
        void connection.reportFailure();
    }
});

if (import.meta.env.PROD) void registerServiceWorker();
