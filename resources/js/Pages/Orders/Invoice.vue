<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { formatBdt } from '@/composables/useCurrency';

/** Plain page (no app chrome). Print stylesheet via Tailwind `print:`; on phones use "Print / save PDF" -> Share/Save as PDF. */
defineProps<{ order: any; business: { name: string; address: string | null; phone: string | null } }>();
const { t } = useI18n();
const print = () => window.print();
</script>

<template>
    <div class="mx-auto max-w-2xl bg-white p-6 text-black print:p-0" style="color-scheme: light">
        <div class="mb-4 flex justify-between gap-2 print:hidden">
            <Link :href="`/app/orders/${order.id}`" class="btn-secondary !text-black">{{ t('common.back') }}</Link>
            <button type="button" class="btn-primary" @click="print">{{ t('orders.print') }}</button>
        </div>
        <header class="flex justify-between border-b border-gray-300 pb-3">
            <div><h1 class="text-xl font-bold">{{ business.name }}</h1><p class="text-sm">{{ business.address }}</p><p class="text-sm">{{ business.phone }}</p></div>
            <div class="text-right text-sm"><p class="font-bold">{{ t('orders.invoice') }} {{ order.order_number }}</p><p>{{ new Date(order.created_at).toLocaleDateString() }}</p></div>
        </header>
        <section class="my-4 text-sm"><p class="font-semibold">{{ order.customer_name }}</p><p>{{ order.phone }}</p><p>{{ order.address }}<span v-if="order.area">, {{ order.area }}</span></p></section>
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-300 text-left"><th class="py-1">Item</th><th class="py-1 text-right">Qty</th><th class="py-1 text-right">Price</th><th class="py-1 text-right">Amount</th></tr></thead>
            <tbody><tr v-for="i in order.items" :key="i.id" class="border-b border-gray-200"><td class="py-1">{{ i.name }}<br /><span class="text-xs text-gray-600">{{ i.sku }}</span></td><td class="py-1 text-right">{{ i.qty }}</td><td class="py-1 text-right">{{ formatBdt(i.unit_price) }}</td><td class="py-1 text-right">{{ formatBdt(i.line_total) }}</td></tr></tbody>
        </table>
        <dl class="ml-auto mt-4 w-64 space-y-1 text-sm">
            <div class="flex justify-between"><dt>{{ t('orders.subtotal') }}</dt><dd>{{ formatBdt(order.items_subtotal) }}</dd></div>
            <div v-if="Number(order.item_discount) + Number(order.order_discount)" class="flex justify-between"><dt>{{ t('orders.discount') }}</dt><dd>-{{ formatBdt((Number(order.item_discount) + Number(order.order_discount)).toFixed(2)) }}</dd></div>
            <div class="flex justify-between"><dt>{{ t('orders.shipping') }}</dt><dd>{{ formatBdt(order.shipping_charge) }}</dd></div>
            <div v-if="Number(order.other_charges)" class="flex justify-between"><dt>{{ t('orders.other') }}</dt><dd>{{ formatBdt(order.other_charges) }}</dd></div>
            <div class="flex justify-between border-t border-gray-300 pt-1 font-bold"><dt>{{ t('orders.total') }}</dt><dd>{{ formatBdt(order.total) }}</dd></div>
            <div class="flex justify-between"><dt>{{ t('orders.advance') }}</dt><dd>{{ formatBdt(order.advance) }}</dd></div>
            <div class="flex justify-between text-base font-bold"><dt>{{ t('orders.cod') }}</dt><dd>{{ formatBdt(order.cod_amount) }}</dd></div>
        </dl>
    </div>
</template>
