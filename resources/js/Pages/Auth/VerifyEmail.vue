<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { purgeClientData } from '@/lib/purge';

defineOptions({ layout: AuthLayout });
const { t } = useI18n();
const ok = computed(() => (usePage().props as any).flash?.success);
async function logout() { await purgeClientData(); router.post('/auth/logout'); }
</script>

<template>
    <div class="space-y-4">
        <h2 class="text-xl font-semibold">{{ t('auth.verifyTitle') }}</h2>
        <p class="text-sm">{{ t('auth.verifyBody') }}</p>
        <p v-if="ok" class="rounded-lg bg-ok/15 px-3 py-2 text-sm" role="status">{{ ok }}</p>
        <div class="flex gap-2">
            <OfflineGuard button-class="btn-primary" @click="router.post('/auth/verify-email/resend')">{{ t('auth.resend') }}</OfflineGuard>
            <OfflineGuard button-class="btn-secondary" @click="logout">{{ t('auth.logout') }}</OfflineGuard>
        </div>
    </div>
</template>
