import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import { isBusy, markBusy, markIdle } from './useBusy';

describe('busy registry (gates SW update, PWA-20)', () => {
    it('is busy while any work is registered', async () => {
        expect(isBusy.value).toBe(false);
        markBusy('form:order');
        markBusy('upload:1');
        await nextTick();
        expect(isBusy.value).toBe(true);
        markIdle('form:order');
        expect(isBusy.value).toBe(true);
        markIdle('upload:1');
        expect(isBusy.value).toBe(false);
    });
});
