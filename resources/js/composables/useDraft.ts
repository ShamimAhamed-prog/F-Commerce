import { DRAFT_PREFIX } from '@/lib/purge';

/**
 * Local unsent drafts (OFF-10): convenience only, never auto-submitted,
 * scoped to user+tenant+form, expiring, purged on logout. Sensitive fields
 * are refused outright (SEC-P5).
 */
const SENSITIVE = /pass(word)?|secret|token|credential|api[_-]?key|card|cvv|otp/i;
export const DRAFT_TTL_MS = 24 * 60 * 60 * 1000;

export interface DraftScope {
    userId: number | string;
    tenantId: number | string;
    form: string;
}

export const draftKey = (s: DraftScope) => `${DRAFT_PREFIX}${s.userId}:${s.tenantId}:${s.form}`;

function store(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export function saveDraft(scope: DraftScope, values: Record<string, unknown>, now = Date.now()): void {
    const safe = Object.fromEntries(Object.entries(values).filter(([k]) => !SENSITIVE.test(k)));
    try {
        store()?.setItem(draftKey(scope), JSON.stringify({ savedAt: now, values: safe }));
    } catch {
        /* quota / blocked: drafts are best-effort */
    }
}

export function loadDraft(scope: DraftScope, now = Date.now()): { values: Record<string, unknown>; savedAt: number } | null {
    const s = store();
    if (!s) return null;
    const key = draftKey(scope);
    try {
        const raw = s.getItem(key);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed?.savedAt || now - parsed.savedAt > DRAFT_TTL_MS) {
            s.removeItem(key);
            return null;
        }
        return parsed;
    } catch {
        s.removeItem(key);
        return null;
    }
}

export function clearDraft(scope: DraftScope): void {
    try {
        store()?.removeItem(draftKey(scope));
    } catch {
        /* ignore */
    }
}

export function useDraft(scope: DraftScope) {
    return {
        save: (values: Record<string, unknown>) => saveDraft(scope, values),
        restore: () => loadDraft(scope),
        clear: () => clearDraft(scope),
    };
}
