<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
defineProps<{ imports: any[]; types: string[]; limits: { max_rows: number; max_file_mb: number } }>();
const { t } = useI18n();
const forms = { products: useForm<{ file: File | null }>({ file: null }), orders: useForm<{ file: File | null }>({ file: null }) } as Record<string, ReturnType<typeof useForm<{ file: File | null }>>>;
const submit = (type: string) => forms[type].post(`/app/imports/${type}`, { forceFormData: true });
const tone = (s: string) => (s === 'completed' ? 'ok' : s === 'failed' ? 'danger' : s === 'completed_with_errors' ? 'warn' : 'muted');
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <h1 class="text-xl font-bold">{{ t('imports.title') }}</h1>
        <section v-for="type in types" :key="type" class="space-y-3 rounded-xl border border-line bg-card p-4" :data-testid="`import-${type}`">
            <h2 class="font-semibold">{{ type === 'products' ? t('imports.productsTitle') : t('orders.importOrders') }}</h2>
            <p v-if="type === 'products'" class="text-sm">{{ t('imports.dataOnly') }}</p>
            <p class="text-xs text-muted">{{ t('imports.limits', { rows: limits.max_rows, mb: limits.max_file_mb }) }}</p>
            <a :href="`/app/imports/${type}/template`" class="btn-secondary" download>{{ t('imports.template') }}</a>
            <form class="space-y-3" novalidate @submit.prevent="submit(type)">
                <div>
                    <label class="label" :for="`file-${type}`">{{ t('imports.chooseFile') }}</label>
                    <input :id="`file-${type}`" type="file" class="field" accept=".csv,.xlsx" :data-testid="`import-file-${type}`" @change="forms[type].file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <p v-if="forms[type].errors.file" class="err" role="alert">{{ forms[type].errors.file }}</p>
                </div>
                <progress v-if="forms[type].progress" class="h-2 w-full" max="100" :value="forms[type].progress?.percentage"></progress>
                <OfflineGuard type="submit" :pending="forms[type].processing" :disabled="!forms[type].file" button-class="btn-primary w-full sm:w-auto">{{ t('imports.upload') }}</OfflineGuard>
            </form>
        </section>

        <section v-if="imports.length">
            <h2 class="mb-2 font-semibold">{{ t('imports.recent') }}</h2>
            <ul class="space-y-2">
                <li v-for="i in imports" :key="i.id">
                    <Link :href="`/app/imports/${i.id}`" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                        <span class="min-w-0"><span class="block truncate font-medium">{{ i.original_name }}</span><span class="text-xs text-muted">{{ i.type }} · {{ t('imports.rows') }}: {{ i.total_rows }}</span></span>
                        <StatusBadge :tone="tone(i.status)">{{ t(`imports.status_${i.status}`) }}</StatusBadge>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
