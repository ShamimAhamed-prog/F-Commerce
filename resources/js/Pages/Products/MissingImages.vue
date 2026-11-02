<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ products: any; filters: { status?: string; category?: string }; categories: { id: number; name: string }[]; canManage: boolean }>();
const { t } = useI18n();
const status = ref(props.filters.status ?? '');
const category = ref(props.filters.category ?? '');
watch([status, category], () => router.get('/app/products/missing-images', { status: status.value || undefined, category: category.value || undefined }, { preserveState: true, replace: true }));
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ t('products.missingTitle') }}</h1>
            <Link v-if="canManage && products.data.length" href="/app/products/missing-images/next" class="btn-primary">{{ t('products.nextMissing') }}</Link>
        </div>
        <div class="flex flex-wrap gap-2">
            <select v-model="status" class="field !w-auto" :aria-label="t('common.status')">
                <option value="">{{ t('common.status') }}: {{ t('common.all') }}</option>
                <option v-for="s in ['draft', 'active', 'archived']" :key="s" :value="s">{{ t(`products.${s}`) }}</option>
            </select>
            <select v-model="category" class="field !w-auto" :aria-label="t('products.category')">
                <option value="">{{ t('products.category') }}: {{ t('common.all') }}</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </div>
        <p v-if="!products.data.length" class="rounded-xl border border-line bg-card p-6 text-center text-sm text-muted">{{ t('products.missingEmpty') }}</p>
        <ul class="space-y-2">
            <li v-for="p in products.data" :key="p.id" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ p.name }}</p>
                    <p class="truncate text-xs text-muted">{{ p.sku }}<span v-if="p.category"> · {{ p.category }}</span></p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <StatusBadge :tone="p.status === 'active' ? 'ok' : 'warn'">{{ t(`products.${p.status}`) }}</StatusBadge>
                    <Link v-if="canManage" :href="`/app/products/${p.id}/edit`" class="btn-secondary !px-3">{{ t('products.addImages') }}</Link>
                </div>
            </li>
        </ul>
        <Pagination :meta="products" />
    </div>
</template>
