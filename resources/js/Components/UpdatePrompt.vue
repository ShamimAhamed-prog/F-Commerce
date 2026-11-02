<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useServiceWorkerUpdate } from '@/composables/useServiceWorkerUpdate';

const { t } = useI18n();
const sw = useServiceWorkerUpdate();
</script>

<template>
    <!-- Non-blocking (PWA-20): sits above the tab bar, never covers content permanently -->
    <div
        v-if="sw.updateAvailable.value"
        role="status"
        class="fixed inset-x-4 z-40 mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl border border-line bg-card p-3 shadow-lg"
        style="bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 0.75rem)"
    >
        <div>
            <p class="text-sm font-semibold">{{ t('pwa.updateAvailable') }}</p>
            <p v-if="sw.deferred.value" class="text-xs text-muted">{{ t('pwa.updateDeferred') }}</p>
        </div>
        <div class="flex shrink-0 gap-2">
            <button class="btn-secondary !px-3" @click="sw.dismiss()">{{ t('pwa.later') }}</button>
            <button class="btn-primary !px-3" @click="sw.apply()">{{ t('pwa.update') }}</button>
        </div>
    </div>
</template>
