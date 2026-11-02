<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ providers: { key: string; label: string; fields: { key: string; label: string }[]; integration: any | null }[] }>();
const { t } = useI18n();
const forms = reactive<Record<string, any>>({});
for (const p of props.providers) forms[p.key] = useForm(Object.fromEntries([['display_name', p.integration?.display_name ?? ''], ...p.fields.map((f) => [f.key, ''])]));
const tone = (s: string) => (s === 'connected' ? 'ok' : s === 'failed' ? 'danger' : 'warn');
const save = (key: string) => forms[key].post(`/app/settings/couriers/${key}`, { preserveScroll: true, onSuccess: () => forms[key].reset() });
const test = (key: string) => router.post(`/app/settings/couriers/${key}/test`, {}, { preserveScroll: true });
const disconnect = (key: string) => window.confirm(t('couriers.confirmDisconnect')) && router.delete(`/app/settings/couriers/${key}`, { preserveScroll: true });
const flash = computed(() => (usePage().props as any).flash ?? {});
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-xl font-bold">{{ t('couriers.title') }}</h1>
        <p class="text-sm text-muted">{{ t('couriers.help') }}</p>
        <p v-if="flash.error" role="alert" class="rounded-lg bg-danger/15 px-3 py-2 text-sm" data-testid="courier-error">{{ flash.error }}</p>

        <section v-for="p in providers" :key="p.key" class="space-y-4 rounded-xl border border-line bg-card p-4" :data-testid="`courier-${p.key}`">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ p.label }}</h2>
                <StatusBadge :tone="p.integration ? tone(p.integration.status) : 'muted'">{{ p.integration ? t(`couriers.status_${p.integration.status}`) : t('couriers.notConnected') }}</StatusBadge>
            </div>
            <p v-if="p.integration?.last_tested_at" class="text-xs text-muted">{{ t('couriers.lastTested') }}: {{ new Date(p.integration.last_tested_at).toLocaleString() }}</p>

            <form class="space-y-3" novalidate autocomplete="off" @submit.prevent="save(p.key)">
                <div v-for="f in p.fields" :key="f.key">
                    <label class="label" :for="`${p.key}-${f.key}`">{{ f.label }}</label>
                    <input :id="`${p.key}-${f.key}`" v-model="forms[p.key][f.key]" class="field font-mono" type="password" autocomplete="off" spellcheck="false" autocapitalize="off"
                        :placeholder="p.integration ? `${p.integration.masked[f.key]} · ${t('couriers.keepBlank')}` : ''" />
                    <p v-if="forms[p.key].errors[f.key]" class="err" role="alert">{{ forms[p.key].errors[f.key] }}</p>
                </div>
                <p class="text-xs text-muted">{{ t('couriers.whereKeys') }}</p>
                <div class="flex flex-wrap gap-2">
                    <OfflineGuard type="submit" :pending="forms[p.key].processing" button-class="btn-primary">{{ p.integration ? t('couriers.rotate') : t('couriers.connect') }}</OfflineGuard>
                    <OfflineGuard v-if="p.integration" button-class="btn-secondary" @click="test(p.key)">{{ t('couriers.retest') }}</OfflineGuard>
                    <OfflineGuard v-if="p.integration" button-class="btn-secondary !text-danger" @click="disconnect(p.key)">{{ t('couriers.disconnect') }}</OfflineGuard>
                </div>
            </form>
        </section>
    </div>
</template>
