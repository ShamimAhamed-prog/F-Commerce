import { computed, ref } from 'vue';
import { UI_PREFIX } from '@/lib/purge';

/** light | dark | system. Persisted as a non-personal UI preference (survives logout purges). */
export type ThemeMode = 'system' | 'light' | 'dark';
const KEY = `${UI_PREFIX}theme`;

function read(): ThemeMode {
    try {
        const v = window.localStorage.getItem(KEY);
        return v === 'light' || v === 'dark' ? v : 'system';
    } catch {
        return 'system';
    }
}

const mode = ref<ThemeMode>(typeof window === 'undefined' ? 'system' : read());
const systemDark = ref(typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-color-scheme: dark)').matches);

export function applyTheme(m: ThemeMode, dark = systemDark.value) {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    m === 'system' ? root.removeAttribute('data-theme') : (root.dataset.theme = m);
    // keep the browser UI colour in step with an explicit override
    const color = (m === 'system' ? dark : m === 'dark') ? '#0a0f1f' : '#f3f5fb';
    document.querySelectorAll('meta[name=theme-color]').forEach((el) => el.setAttribute('content', color));
}

let listening = false;
export function useTheme() {
    if (!listening && typeof window !== 'undefined') {
        listening = true;
        const mq = window.matchMedia?.('(prefers-color-scheme: dark)');
        mq?.addEventListener?.('change', (e) => {
            systemDark.value = e.matches;
            if (mode.value === 'system') applyTheme('system', e.matches);
        });
        applyTheme(mode.value);
    }
    return {
        mode,
        isDark: computed(() => (mode.value === 'system' ? systemDark.value : mode.value === 'dark')),
        set(m: ThemeMode) {
            mode.value = m;
            try {
                m === 'system' ? window.localStorage.removeItem(KEY) : window.localStorage.setItem(KEY, m);
            } catch {
                /* storage may be blocked: the choice still applies for this session */
            }
            applyTheme(m);
        },
    };
}
