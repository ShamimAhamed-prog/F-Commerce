import { describe, expect, it } from 'vitest';
import { detectIosSafari, shouldShowAndroidBanner, shouldShowIosHelper } from './useInstall';

const DAY = 86_400_000;
const base = { isIos: true, isSafari: true, standalone: false, dismissedAt: null, now: 100 * DAY };

describe('install prompt logic', () => {
    it('shows the iOS helper in Safari when not installed', () => {
        expect(shouldShowIosHelper(base)).toBe(true);
    });
    it('never shows inside standalone mode', () => {
        expect(shouldShowIosHelper({ ...base, standalone: true })).toBe(false);
    });
    it('hides on non-iOS and non-Safari iOS browsers', () => {
        expect(shouldShowIosHelper({ ...base, isIos: false })).toBe(false);
        expect(shouldShowIosHelper({ ...base, isSafari: false })).toBe(false);
    });
    it('respects the cooldown after dismissal, then re-shows', () => {
        expect(shouldShowIosHelper({ ...base, dismissedAt: 95 * DAY })).toBe(false);
        expect(shouldShowIosHelper({ ...base, dismissedAt: 80 * DAY })).toBe(true);
    });
    it('detects iPhone Safari vs Chrome-on-iOS and iPadOS desktop UA', () => {
        const safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        const chrome = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120 Mobile/15E148 Safari/604.1';
        const ipad = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        expect(detectIosSafari(safari, 5)).toEqual({ isIos: true, isSafari: true });
        expect(detectIosSafari(chrome, 5)).toEqual({ isIos: true, isSafari: false });
        expect(detectIosSafari(ipad, 5).isIos).toBe(true);
        expect(detectIosSafari(ipad, 0).isIos).toBe(false);
    });
    it('shows the Android banner only with a captured prompt, not installed, outside cooldown', () => {
        expect(shouldShowAndroidBanner(true, false, null, 5 * DAY)).toBe(true);
        expect(shouldShowAndroidBanner(false, false, null, 5 * DAY)).toBe(false);
        expect(shouldShowAndroidBanner(true, true, null, 5 * DAY)).toBe(false);
        expect(shouldShowAndroidBanner(true, false, 4 * DAY, 5 * DAY)).toBe(false);
    });
});
