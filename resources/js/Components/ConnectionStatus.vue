<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConnection } from '@/composables/useConnection';

const props = withDefaults(defineProps<{ variant?: 'chip' | 'banner' }>(), { variant: 'chip' });
const { t } = useI18n();
const conn = useConnection();

const dot = computed(
    () =>
        ({
            online: 'bg-ok',
            slow: 'bg-warn',
            offline: 'bg-danger',
            unreachable: 'bg-danger',
        })[conn.state.value],
);
const label = computed(() => t(`connection.${conn.state.value}`));
const bannerText = computed(() => {
    switch (conn.state.value) {
        case 'offline':
            return t('connection.offlineBanner');
        case 'unreachable':
            return t('connection.unreachableBanner');
        case 'slow':
            return t('connection.slowBanner');
        default:
            return '';
    }
});
const showBanner = computed(() => conn.state.value !== 'online');
</script>

<template>
    <!-- OFF-4: announced politely to assistive tech -->
    <div v-if="props.variant === 'chip'" role="status" aria-live="polite" class="flex items-center gap-2 text-xs">
        <span class="h-2.5 w-2.5 rounded-full" :class="dot" aria-hidden="true"></span>
        <span class="hidden sm:inline">{{ label }}</span>
        <span class="sr-only sm:hidden">{{ label }}</span>
    </div>

    <div v-else role="status" aria-live="polite" data-testid="connection-banner">
        <div
            v-if="showBanner"
            class="flex items-center justify-between gap-3 px-4 py-2 text-sm"
            :class="conn.state.value === 'slow' ? 'bg-warn/15 text-ink' : 'bg-danger/15 text-ink'"
        >
            <span>{{ bannerText }}</span>
            <button v-if="conn.state.value !== 'slow'" class="btn-secondary !min-h-[36px] !px-3" @click="conn.probe()">
                {{ t('connection.retry') }}
            </button>
        </div>
        <div v-else-if="conn.justRecovered.value" class="bg-ok/15 px-4 py-2 text-sm">{{ t('connection.backOnline') }}</div>
    </div>
</template>
