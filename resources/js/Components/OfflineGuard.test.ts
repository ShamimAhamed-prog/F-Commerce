import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import en from '@/i18n/en';

const blocked = { value: false };
vi.mock('@/composables/useConnection', () => ({ useConnection: () => ({ writesBlocked: blocked }) }));

import OfflineGuard from './OfflineGuard.vue';

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });
const make = (props = {}) => mount(OfflineGuard, { props, slots: { default: 'Save' }, global: { plugins: [i18n] } });

describe('OfflineGuard', () => {
    it('enables the button when reachable', () => {
        blocked.value = false;
        const w = make();
        expect(w.get('button').attributes('disabled')).toBeUndefined();
        expect(w.text()).not.toContain('Needs internet');
    });

    it('disables with a visible reason when offline and does not emit clicks', async () => {
        blocked.value = true;
        const w = mount(OfflineGuard, {
            slots: { default: 'Book courier' },
            attrs: { onClick: vi.fn() },
            global: { plugins: [i18n] },
        });
        const btn = w.get('button');
        expect(btn.attributes('disabled')).toBeDefined();
        expect(w.text()).toContain('Needs internet');
        expect(btn.attributes('aria-describedby')).toBeTruthy();
        await btn.trigger('click');
        expect((w.attributes() as any).onClick).toBeUndefined();
    });

    it('locks while pending (double-submit protection)', () => {
        blocked.value = false;
        expect(make({ pending: true }).get('button').attributes('disabled')).toBeDefined();
    });
});
