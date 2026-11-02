import { computed, ref } from 'vue';
import { UI_PREFIX } from '@/lib/purge';

/** Install UX for Android (beforeinstallprompt) and iOS (manual helper). PWA-9, PWA-13, PWA-14. */

const COOLDOWN_DAYS = 14;
const DISMISS_KEY = `${UI_PREFIX}install-dismissed-at`;

export interface IosHelperInput {
    isIos: boolean;
    isSafari: boolean;
    standalone: boolean;
    dismissedAt: number | null;
    now: number;
    cooldownDays?: number;
}

/** Pure decision, unit-tested. Never in standalone; respects cooldown after dismissal. */
export function shouldShowIosHelper(i: IosHelperInput): boolean {
    if (!i.isIos || !i.isSafari || i.standalone) return false;
    if (i.dismissedAt && i.now - i.dismissedAt < (i.cooldownDays ?? COOLDOWN_DAYS) * 86_400_000) return false;
    return true;
}

export function shouldShowAndroidBanner(hasPrompt: boolean, standalone: boolean, dismissedAt: number | null, now: number): boolean {
    if (!hasPrompt || standalone) return false;
    return !(dismissedAt && now - dismissedAt < COOLDOWN_DAYS * 86_400_000);
}

export function detectStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    return (
        (window.matchMedia?.('(display-mode: standalone)').matches ?? false) ||
        (navigator as any).standalone === true
    );
}

export function detectIosSafari(ua = navigator.userAgent, maxTouchPoints = navigator.maxTouchPoints ?? 0) {
    // iPadOS 13+ reports as Macintosh with touch points.
    const isIos = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && maxTouchPoints > 1);
    const isSafari = isIos && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
    return { isIos, isSafari };
}

const deferredPrompt = ref<any>(null);
const installed = ref(false);
const standalone = ref(detectStandalone());
const dismissedAt = ref<number | null>(readDismissed());
let listening = false;

function readDismissed(): number | null {
    try {
        const v = window.localStorage.getItem(DISMISS_KEY);
        return v ? Number(v) : null;
    } catch {
        return null;
    }
}

function listen() {
    if (listening || typeof window === 'undefined') return;
    listening = true;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt.value = e;
    });
    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
        // PWA-14: analytics event without PII would be emitted here.
    });
}

export function useInstall() {
    listen();
    const ios = detectIosSafari();

    return {
        installed,
        canPromptAndroid: computed(() =>
            shouldShowAndroidBanner(!!deferredPrompt.value, standalone.value || installed.value, dismissedAt.value, Date.now()),
        ),
        hasNativePrompt: computed(() => !!deferredPrompt.value),
        showIosHelper: computed(() =>
            shouldShowIosHelper({ ...ios, standalone: standalone.value, dismissedAt: dismissedAt.value, now: Date.now() }),
        ),
        /** Must be called from a user gesture. */
        async promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
            const p = deferredPrompt.value;
            if (!p) return 'unavailable';
            await p.prompt();
            const choice = await p.userChoice;
            deferredPrompt.value = null;
            if (choice?.outcome === 'dismissed') this.dismiss();
            return choice?.outcome ?? 'dismissed';
        },
        dismiss() {
            dismissedAt.value = Date.now();
            try {
                window.localStorage.setItem(DISMISS_KEY, String(dismissedAt.value));
            } catch {
                /* ignore */
            }
        },
    };
}
