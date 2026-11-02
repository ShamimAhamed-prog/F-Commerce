<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/Components/Pagination.vue';
import ShipmentCard from '@/Components/ShipmentCard.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';
import { telHref } from '@/lib/phone';

defineOptions({ layout: AppLayout });
const props = defineProps<{ shipments: any; filters: { state?: string; search?: string }; canManage: boolean }>();
const { t } = useI18n();
const search = ref(props.filters.search ?? '');
const state = ref(props.filters.state ?? '');
let timer: ReturnType<typeof setTimeout>;
const go = () => router.get('/app/shipments', { search: search.value || undefined, state: state.value || undefined }, { preserveState: true, replace: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(go, 300); });
watch(state, go);
const states = ['booked', 'booking_unknown', 'failed'];
</script>

<template>
    <div class="mx-auto w-full max-w-5xl space-y-4">
        <div><p class="eyebrow">{{ t('nav.g_sales') }}</p><h1 class="page-title mt-1">{{ t('nav.shipments') }}</h1></div>
        <div class="sticky top-16 z-10 -mx-4 space-y-2 bg-surface/90 px-4 py-2 backdrop-blur sm:mx-0 sm:px-0">
            <input v-model="search" type="search" class="field" :placeholder="t('ship.searchPlaceholder')" :aria-label="t('common.search')" />
            <div class="flex flex-wrap gap-1.5">
                <button type="button" class="rounded-full border px-3.5 py-1.5 text-sm" :class="!state ? 'border-brand bg-brand-soft font-semibold text-brand' : 'border-line text-muted'" @click="state = ''">{{ t('ship.all') }}</button>
                <button v-for="x in states" :key="x" type="button" class="rounded-full border px-3.5 py-1.5 text-sm" :class="state === x ? 'border-brand bg-brand-soft font-semibold text-brand' : 'border-line text-muted'" @click="state = x">{{ t(`ship.${x === 'booking_unknown' ? 'unknown' : x}`) }}</button>
            </div>
        </div>
        <p v-if="!shipments.data.length" class="card p-8 text-center text-sm text-muted">{{ t('ship.noShipments') }}</p>
        <ul class="space-y-3">
            <li v-for="s in shipments.data" :key="s.id" class="card p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <Link :href="`/app/orders/${s.order.id}`" class="font-semibold hover:underline">{{ s.order.order_number }} <span class="font-normal text-muted">· {{ s.order.customer_name }}</span></Link>
                    <span class="flex items-center gap-2"><span class="tabular text-muted">COD {{ formatBdt(s.cod_amount) }}</span><a :href="telHref(s.order.phone)" class="btn-secondary !min-h-[40px] !px-3">📞</a></span>
                </div>
                <ShipmentCard :shipment="s" :can-manage="canManage" />
            </li>
        </ul>
        <Pagination :meta="shipments" />
    </div>
</template>
