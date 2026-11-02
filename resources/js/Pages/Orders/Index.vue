<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ orders: any; filters: { search?: string; status?: string }; statuses: string[] }>();
const { t } = useI18n();
const { can } = usePermissions();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
let timer: ReturnType<typeof setTimeout>;
const apply = () => router.get('/app/orders', { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
watch(status, apply);
const tone = (s: string) => (['delivered'].includes(s) ? 'ok' : ['cancelled', 'delivery_failed', 'returned'].includes(s) ? 'danger' : ['draft'].includes(s) ? 'muted' : 'warn');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ t('orders.title') }}</h1>
            <div class="flex gap-2">
                <Link v-if="can('orders.import')" href="/app/imports" class="btn-secondary">{{ t('orders.importOrders') }}</Link>
                <Link v-if="can('orders.create')" href="/app/orders/create" class="btn-primary">{{ t('orders.new') }}</Link>
            </div>
        </div>
        <div class="sticky top-16 z-10 -mx-4 space-y-2 bg-surface px-4 py-2 sm:mx-0 sm:px-0">
            <input v-model="search" type="search" class="field" :placeholder="t('orders.searchPlaceholder')" :aria-label="t('common.search')" />
            <select v-model="status" class="field !w-auto" :aria-label="t('common.status')">
                <option value="">{{ t('common.status') }}: {{ t('common.all') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ t(`orders.status_${s}`) }}</option>
            </select>
        </div>
        <p v-if="!orders.data.length" class="rounded-xl border border-line bg-card p-6 text-center text-sm text-muted">{{ t('orders.noOrders') }}</p>
        <ul class="space-y-2">
            <li v-for="o in orders.data" :key="o.id">
                <Link :href="`/app/orders/${o.id}`" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ o.order_number }} <span class="font-normal text-muted">· {{ o.customer_name }}</span></p>
                        <p class="text-xs text-muted">{{ o.phone }}</p>
                        <p class="mt-1 text-sm">{{ formatBdt(o.total) }} · {{ t('orders.cod') }} {{ formatBdt(o.cod_amount) }}</p>
                    </div>
                    <StatusBadge :tone="tone(o.status)">{{ t(`orders.status_${o.status}`) }}</StatusBadge>
                </Link>
            </li>
        </ul>
        <Pagination :meta="orders" />
    </div>
</template>
