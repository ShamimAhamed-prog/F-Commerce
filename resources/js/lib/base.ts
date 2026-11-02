/**
 * Sub-folder deployment (cPanel: https://host/fcom-manager). The ONE value comes from APP_BASE_PATH on the server,
 * rendered into <meta name="app-base">. Source code keeps writing root-relative paths ('/app/orders'); everything
 * that leaves the browser goes through withBase(). Empty string = served from the domain root (no-op).
 */
const read = (): string => {
    if (typeof document === 'undefined') return '';
    const v = document.querySelector<HTMLMetaElement>('meta[name="app-base"]')?.content ?? '';
    const t = v.trim().replace(/^\/+|\/+$/g, '');
    return t ? `/${t}` : '';
};

export const BASE = read();

/** '/app/x' -> '/fcom-manager/app/x'. Idempotent; leaves absolute/other-origin URLs, '#', 'tel:' etc. alone. */
export function withBase(path: string, base: string = BASE): string {
    if (!base || !path.startsWith('/') || path.startsWith('//')) return path;
    return path === base || path.startsWith(`${base}/`) || path.startsWith(`${base}?`) ? path : base + path;
}

/** Same, for a full http(s) URL on this origin (what Inertia hands to its HTTP client). */
export function withBaseUrl(url: string, base: string = BASE): string {
    if (!base) return url;
    try {
        const u = new URL(url, window.location.origin);
        if (u.origin !== window.location.origin) return url;
        u.pathname = withBase(u.pathname, base);
        return /^https?:/i.test(url) ? u.href : u.pathname + u.search + u.hash;
    } catch {
        return url;
    }
}

/** '/fcom-manager/app/x?y' -> '/app/x?y' (for matching the current URL against root-relative nav paths). */
export function stripBase(path: string, base: string = BASE): string {
    return base && (path === base || path.startsWith(`${base}/`) || path.startsWith(`${base}?`)) ? path.slice(base.length) || '/' : path;
}

/** Plain <a href="/app/..."> (and Inertia <Link>'s rendered href) are root-relative too: fix them as they appear,
 *  so middle-click / "open in new tab" / downloads land inside the sub-folder. No-op when BASE is ''. */
export function installAnchorRewriter(): void {
    if (!BASE || typeof document === 'undefined') return;
    const fix = (a: Element) => {
        const h = a.getAttribute('href');
        if (h && h.startsWith('/') && !h.startsWith('//')) {
            const next = withBase(h);
            if (next !== h) a.setAttribute('href', next);
        }
    };
    const scan = (n: Node) => {
        if (n.nodeType !== 1) return;
        const el = n as Element;
        if (el.tagName === 'A') fix(el);
        el.querySelectorAll?.('a[href^="/"]').forEach(fix);
    };
    new MutationObserver((muts) => {
        for (const m of muts) {
            if (m.type === 'attributes') fix(m.target as Element);
            else m.addedNodes.forEach(scan);
        }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    scan(document.documentElement);
}
