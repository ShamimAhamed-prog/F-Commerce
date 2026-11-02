import { beforeEach, describe, expect, it } from 'vitest';
import { purgeClientData } from '@/lib/purge';
import { DRAFT_TTL_MS, clearDraft, draftKey, loadDraft, saveDraft } from './useDraft';

const scope = { userId: 1, tenantId: 7, form: 'order' };

describe('useDraft', () => {
    beforeEach(() => sessionStorage.clear());

    it('round-trips values scoped to user+tenant+form', () => {
        saveDraft(scope, { customer: 'Rahim' });
        expect(loadDraft(scope)?.values).toEqual({ customer: 'Rahim' });
        expect(loadDraft({ ...scope, tenantId: 8 })).toBeNull();
        expect(loadDraft({ ...scope, userId: 2 })).toBeNull();
        expect(loadDraft({ ...scope, form: 'expense' })).toBeNull();
    });

    it('refuses to store sensitive fields', () => {
        saveDraft(scope, { name: 'x', password: 'p', api_key: 'k', courier_secret: 's', otp: '1' });
        expect(loadDraft(scope)?.values).toEqual({ name: 'x' });
    });

    it('expires after 24h and removes the entry', () => {
        saveDraft(scope, { a: 1 }, 1000);
        expect(loadDraft(scope, 1000 + DRAFT_TTL_MS + 1)).toBeNull();
        expect(sessionStorage.getItem(draftKey(scope))).toBeNull();
    });

    it('clears on demand', () => {
        saveDraft(scope, { a: 1 });
        clearDraft(scope);
        expect(loadDraft(scope)).toBeNull();
    });

    it('is purged on logout but kept on session expiry (OFF-12)', async () => {
        saveDraft(scope, { a: 1 });
        sessionStorage.setItem('fc:other', 'x');
        await purgeClientData({ keepDrafts: true });
        expect(loadDraft(scope)).not.toBeNull();
        expect(sessionStorage.getItem('fc:other')).toBeNull();
        await purgeClientData();
        expect(loadDraft(scope)).toBeNull();
    });

    it('keeps non-personal UI prefs across purges', async () => {
        localStorage.setItem('fc:ui:install-dismissed-at', '1');
        localStorage.setItem('leak', 'tenant data');
        await purgeClientData();
        expect(localStorage.getItem('fc:ui:install-dismissed-at')).toBe('1');
        expect(localStorage.getItem('leak')).toBeNull();
    });
});
