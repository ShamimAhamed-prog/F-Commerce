<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';
import { telHref, waHref } from '@/lib/phone';

defineOptions({ layout: AppLayout });
const props = defineProps<{ order: any; actions: string[] }>();
const { t } = useI18n();
function move(to: string) {
    if (to === 'cancelled' && !window.confirm(t('orders.cancelConfirm'))) return;
    router.post(`/app/orders/${props.order.id}/status`, { status: to }, { preserveScroll: true });
}
const row = (label: string, v: string) => ({ label, v });
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div><h1 class="text-xl font-bold">{{ order.order_number }}</h1><StatusBadge tone="warn">{{ t(`orders.status_${order.status}`) }}</StatusBadge></div>
            <div class="flex gap-2"><Link href="/app/orders" class="btn-secondary">{{ t('common.back') }}</Link>
                <Link :href="`/app/orders/${order.id}/invoice`" class="btn-secondary">{{ t('orders.invoice') }}</Link></div>
        </div>

        <section class="space-y-2 rounded-xl border border-line bg-card p-4 text-sm">
            <p class="font-semibold"><Link v-if="order.customer" :href="`/app/customers/${order.customer.id}`" class="hover:underline">{{ order.customer_name }}</Link><span v-else>{{ order.customer_name }}</span></p>
            <p class="text-muted">{{ order.address }}<span v-if="order.area">, {{ order.area }}</span></p>
            <div class="flex flex-wrap gap-2">
                <a :href="telHref(order.phone)" class="btn-secondary">{{ t('orders.call') }} {{ order.phone }}</a>
                <a :href="waHref(order.phone)" target="_blank" rel="noopener" class="btn-secondary">{{ t('orders.whatsapp') }}</a>
            </div>
            <p v-if="order.notes" class="text-muted">{{ order.notes }}</p>
        </section>

        <section class="rounded-xl border border-line bg-card p-4">
            <h2 class="mb-2 font-semibold">{{ t('orders.items') }}</h2>
            <ul class="divide-y divide-line text-sm"><li v-for="i in order.items" :key="i.id" class="flex justify-between gap-3 py-2"><span class="min-w-0"><span class="block truncate">{{ i.qty }} × {{ i.name }}</span><span class="text-xs text-muted">{{ i.sku }} @ {{ formatBdt(i.unit_price) }}</span></span><span>{{ formatBdt(i.line_total) }}</span></li></ul>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt>{{ t('orders.subtotal') }}</dt><dd>{{ formatBdt(order.items_subtotal) }}</dd></div>
                <div v-if="Number(order.item_discount) + Number(order.order_discount)" class="flex justify-between"><dt>{{ t('orders.discount') }}</dt><dd>-{{ formatBdt((Number(order.item_discount) + Number(order.order_discount)).toFixed(2)) }}</dd></div>
                <div class="flex justify-between"><dt>{{ t('orders.shipping') }}</dt><dd>{{ formatBdt(order.shipping_charge) }}</dd></div>
                <div v-if="Number(order.other_charges)" class="flex justify-between"><dt>{{ t('orders.other') }}</dt><dd>{{ formatBdt(order.other_charges) }}</dd></div>
                <div v-if="Number(order.tax_amount)" class="flex justify-between"><dt>{{ t('orders.tax') }}</dt><dd>{{ formatBdt(order.tax_amount) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-1 font-bold"><dt>{{ t('orders.total') }}</dt><dd>{{ formatBdt(order.total) }}</dd></div>
                <div class="flex justify-between"><dt>{{ t('orders.advance') }}</dt><dd>{{ formatBdt(order.advance) }}</dd></div>
                <div class="flex justify-between font-bold"><dt>{{ t('orders.cod') }}</dt><dd data-testid="cod">{{ formatBdt(order.cod_amount) }}</dd></div>
            </dl>
        </section>

        <div v-if="actions.length" class="flex flex-wrap gap-2">
            <OfflineGuard v-for="a in actions" :key="a" :button-class="a === 'cancelled' ? 'btn-secondary !text-danger' : a === actions[0] ? 'btn-primary' : 'btn-secondary'" :data-status="a" @click="move(a)">{{ t(`orders.move_${a}`) }}</OfflineGuard>
        </div>

        <section class="rounded-xl border border-line bg-card p-4">
            <h2 class="mb-2 font-semibold">{{ t('orders.history') }}</h2>
            <ol class="space-y-1 text-sm"><li v-for="h in order.histories" :key="h.id"><StatusBadge>{{ t(`orders.status_${h.new_status}`) }}</StatusBadge> <span class="text-xs text-muted">{{ new Date(h.created_at).toLocaleString() }}<span v-if="h.note"> · {{ h.note }}</span></span></li></ol>
        </section>
    </div>
</template>
