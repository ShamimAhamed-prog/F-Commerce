<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useInstall } from '@/composables/useInstall';

const { t } = useI18n();
const install = useInstall();
</script>

<template>
    <!-- Android / Chromium soft banner (PWA-13) -->
    <div
        v-if="install.canPromptAndroid.value"
        class="mx-4 mb-3 flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3 shadow-sm"
    >
        <div>
            <p class="text-sm font-semibold">{{ t('pwa.installTitle') }}</p>
            <p class="text-xs text-muted">{{ t('pwa.installBody') }}</p>
        </div>
        <div class="flex shrink-0 gap-2">
            <button class="btn-secondary !px-3" @click="install.dismiss()">{{ t('pwa.notNow') }}</button>
            <button class="btn-primary !px-3" @click="install.promptInstall()">{{ t('pwa.installApp') }}</button>
        </div>
    </div>

    <!-- iOS helper (PWA-9): Safari only, never in standalone, cooldown after dismissal -->
    <div v-else-if="install.showIosHelper.value" class="mx-4 mb-3 rounded-xl border border-line bg-card p-3 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-semibold">{{ t('pwa.iosTitle') }}</p>
            <button class="btn-secondary !min-h-[36px] !px-3" @click="install.dismiss()">{{ t('pwa.notNow') }}</button>
        </div>
        <ol class="mt-2 space-y-2 text-sm">
            <li class="flex items-center gap-2">
                <svg class="h-6 w-6 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                {{ t('pwa.iosStep1') }}
            </li>
            <li class="flex items-center gap-2">
                <svg class="h-6 w-6 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="3" /><path d="M12 8v8M8 12h8" stroke-linecap="round" />
                </svg>
                {{ t('pwa.iosStep2') }}
            </li>
        </ol>
    </div>
</template>
