<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConnection } from '@/composables/useConnection';

defineOptions({ layout: AppLayout });
const props = defineProps<{ import: any; preview: any[]; problems: any[]; fields: { key: string; label: string }[]; missingImages: number }>();
const { t } = useI18n();
const conn = useConnection();
const i = computed(() => props.import);

const mapping = reactive<Record<string, string | null>>({ ...(props.import.mapping ?? {}) });
const policy = ref(props.import.duplicate_policy);
watch(() => props.import.mapping, (m) => Object.assign(mapping, m ?? {}));

// Poll while the SERVER works; the server-side job continues regardless of this client's connection (§8).
const working = computed(() => ['uploaded', 'parsing', 'processing'].includes(i.value.status) || i.value.validating);
let timer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    timer = setInterval(() => {
        if (working.value) router.reload({ only: ['import', 'preview', 'problems', 'missingImages'] });
    }, 1500);
});
onBeforeUnmount(() => timer && clearInterval(timer));

const mapForm = useForm({});
function saveMapping() {
    mapForm.transform(() => ({ mapping: { ...mapping }, duplicate_policy: policy.value })).post(`/app/imports/${i.value.id}/mapping`, { preserveScroll: true });
}
const post = (path: string) => router.post(`/app/imports/${i.value.id}/${path}`, {}, { preserveScroll: true });
const isIgnored = (h: string) => (i.value.ignored_columns ?? []).includes(h);
const tone = computed(() => ({ completed: 'ok', failed: 'danger', completed_with_errors: 'warn' } as Record<string, any>)[i.value.status] ?? 'muted');
const mapError = computed(() => (mapForm.errors as Record<string, string>).mapping);
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0"><h1 class="truncate text-xl font-bold">{{ i.original_name }}</h1>
                <StatusBadge :tone="tone">{{ t(`imports.status_${i.status}`) }}</StatusBadge></div>
            <Link href="/app/imports" class="btn-secondary">{{ t('common.back') }}</Link>
        </div>

        <p v-if="conn.state.value === 'offline' || conn.state.value === 'unreachable'" class="rounded-lg bg-warn/15 px-3 py-2 text-sm" role="status">
            {{ t('connection.offline') }}: the import keeps running on the server.
        </p>

        <p v-if="working" class="rounded-xl border border-line bg-card p-4 text-sm" role="status" aria-live="polite">
            {{ i.validating ? t('imports.validating') : i.status === 'processing' ? t('imports.starting') : t('imports.parsing') }}
        </p>

        <section v-if="i.status === 'failed'" class="rounded-xl border border-danger bg-card p-4">
            <h2 class="font-semibold text-danger">{{ t('imports.failedTitle') }}</h2><p class="text-sm">{{ i.error }}</p>
        </section>

        <!-- Map + preview -->
        <template v-if="i.status === 'preview_ready' && !i.validating">
            <section class="space-y-3 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('imports.mapColumns') }}</h2>
                <p v-if="mapError" class="err" role="alert">{{ mapError }}</p>
                <ul class="space-y-3">
                    <li v-for="h in i.headers" :key="h" class="grid gap-1 sm:grid-cols-2 sm:items-center sm:gap-3">
                        <span class="truncate text-sm font-medium">{{ h }}</span>
                        <span v-if="isIgnored(h)" class="text-sm text-muted" data-testid="ignored-column">{{ t('imports.ignored') }}</span>
                        <select v-else v-model="mapping[h]" class="field" :aria-label="`${t('imports.field')}: ${h}`">
                            <option :value="null">{{ t('imports.doNotImport') }}</option>
                            <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }}</option>
                        </select>
                    </li>
                </ul>
                <div>
                    <label class="label" for="policy">{{ t('imports.duplicate') }}</label>
                    <select id="policy" v-model="policy" class="field">
                        <option value="skip">{{ t('imports.skip') }}</option>
                        <option value="update">{{ t('imports.update') }}</option>
                        <option value="create_new_sku">{{ t('imports.newSku') }}</option>
                    </select>
                </div>
                <OfflineGuard :pending="mapForm.processing" button-class="btn-secondary" @click="saveMapping">{{ t('imports.validateSave') }}</OfflineGuard>
            </section>

            <section v-if="preview.length" class="space-y-2">
                <h2 class="font-semibold">{{ t('imports.preview') }}</h2>
                <div class="overflow-x-auto rounded-xl border border-line bg-card">
                    <table class="w-full text-xs">
                        <thead class="border-b border-line text-left text-muted"><tr><th class="p-2">#</th><th v-for="h in i.headers.filter((x: string) => !isIgnored(x))" :key="h" class="p-2">{{ h }}</th></tr></thead>
                        <tbody>
                            <tr v-for="r in preview" :key="r.row" class="border-b border-line last:border-0" :class="{ 'bg-danger/10': r.status === 'invalid' }">
                                <td class="p-2">{{ r.row }}</td>
                                <td v-for="h in i.headers.filter((x: string) => !isIgnored(x))" :key="h" class="max-w-[12rem] truncate p-2">{{ r.cells[h] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="i.validated" class="space-y-3 rounded-xl border border-line bg-card p-4">
                <p class="text-sm"><StatusBadge tone="ok">{{ t('imports.validRows', { n: i.valid_rows }) }}</StatusBadge>
                    <StatusBadge v-if="i.invalid_rows" tone="danger" class="ml-2">{{ t('imports.invalidRows', { n: i.invalid_rows }) }}</StatusBadge>
                    <StatusBadge v-if="i.duplicate_products" tone="warn" class="ml-2">{{ t('imports.duplicates', { n: i.duplicate_products }) }}</StatusBadge></p>
                <ul v-if="problems.length" class="max-h-48 space-y-1 overflow-y-auto text-sm">
                    <li v-for="p in problems" :key="p.row"><strong>{{ t('imports.row') }} {{ p.row }}:</strong> {{ (p.errors ?? []).join(' ') }}</li>
                </ul>
                <div class="flex flex-wrap gap-2">
                    <OfflineGuard :disabled="!i.valid_rows" button-class="btn-primary" @click="post('commit')">{{ t('imports.start', { n: i.valid_rows }) }}</OfflineGuard>
                    <OfflineGuard button-class="btn-secondary" @click="post('cancel')">{{ t('imports.cancelImport') }}</OfflineGuard>
                </div>
            </section>
        </template>

        <!-- Result -->
        <section v-if="['completed', 'completed_with_errors'].includes(i.status)" class="space-y-4 rounded-xl border border-line bg-card p-4">
            <h2 class="font-semibold">{{ t('imports.result') }}</h2>
            <dl class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
                <div><dt class="text-xs text-muted">{{ t('imports.created') }}</dt><dd class="text-2xl font-bold" data-testid="n-created">{{ i.created_count }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('imports.updated') }}</dt><dd class="text-2xl font-bold">{{ i.updated_count }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('imports.skipped') }}</dt><dd class="text-2xl font-bold">{{ i.skipped_count }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('imports.failedLabel') }}</dt><dd class="text-2xl font-bold">{{ i.failed_count }}</dd></div>
            </dl>
            <ul v-if="problems.length" class="max-h-48 space-y-1 overflow-y-auto text-sm">
                <li v-for="p in problems" :key="p.row"><strong>{{ t('imports.row') }} {{ p.row }}:</strong> {{ (p.errors ?? []).join(' ') }}</li>
            </ul>
            <div class="flex flex-wrap gap-2">
                <a v-if="i.failed_count || i.skipped_count" :href="`/app/imports/${i.id}/errors`" class="btn-secondary" download>{{ t('imports.downloadErrors') }}</a>
                <OfflineGuard v-if="i.failed_count" button-class="btn-secondary" @click="post('retry')">{{ t('imports.retryFailed') }}</OfflineGuard>
            </div>
            <!-- IMG-I5 -->
            <div v-if="missingImages" class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-warn/15 p-3 text-sm" data-testid="missing-images-cta">
                <span>{{ t('imports.noImages', { n: missingImages }) }}</span>
                <Link href="/app/products/missing-images" class="btn-primary">{{ t('imports.addImagesCta') }}</Link>
            </div>
        </section>
    </div>
</template>
