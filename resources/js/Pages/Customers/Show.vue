<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';
import { telHref, waHref } from '@/lib/phone';

defineOptions({ layout: AppLayout });
const props = defineProps<{ customer: any; orders: any[]; stats: Record<string, any>; canManage: boolean }>();
const { t } = useI18n();
const c = props.customer;
const form = useForm({ name: c.name, alt_phone: c.alt_phone ?? '', address: c.address ?? '', area: c.area ?? '', notes: c.notes ?? '' });
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex items-center justify-between gap-2"><h1 class="text-xl font-bold">{{ customer.name }}</h1><Link href="/app/customers" class="btn-secondary">{{ t('common.back') }}</Link></div>
        <div class="flex flex-wrap gap-2"><a :href="telHref(customer.phone)" class="btn-secondary">{{ t('orders.call') }} {{ customer.phone }}</a><a :href="waHref(customer.phone)" target="_blank" rel="noopener" class="btn-secondary">{{ t('orders.whatsapp') }}</a></div>
        <dl class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
            <div class="rounded-xl border border-line bg-card p-3"><dt class="text-xs text-muted">{{ t('customers.orders') }}</dt><dd class="text-xl font-bold">{{ stats.total }}</dd></div>
            <div class="rounded-xl border border-line bg-card p-3"><dt class="text-xs text-muted">{{ t('customers.delivered') }}</dt><dd class="text-xl font-bold">{{ stats.delivered }}</dd></div>
            <div class="rounded-xl border border-line bg-card p-3"><dt class="text-xs text-muted">{{ t('customers.cancelled') }} / {{ t('customers.returned') }}</dt><dd class="text-xl font-bold">{{ stats.cancelled }} / {{ stats.returned }}</dd></div>
            <div class="rounded-xl border border-line bg-card p-3"><dt class="text-xs text-muted">{{ t('customers.lifetime') }}</dt><dd class="text-xl font-bold">{{ formatBdt(stats.lifetime) }}</dd></div>
        </dl>
        <form v-if="canManage" class="space-y-3 rounded-xl border border-line bg-card p-4" novalidate @submit.prevent="form.patch(`/app/customers/${customer.id}`, { preserveScroll: true })">
            <div><label class="label" for="cn">{{ t('orders.customer') }}</label><input id="cn" v-model="form.name" class="field" /></div>
            <div><label class="label" for="ap">{{ t('customers.altPhone') }}</label><input id="ap" v-model="form.alt_phone" class="field" type="tel" inputmode="tel" /><p v-if="form.errors.alt_phone" class="err" role="alert">{{ form.errors.alt_phone }}</p></div>
            <div><label class="label" for="ad">{{ t('orders.address') }}</label><textarea id="ad" v-model="form.address" rows="2" class="field"></textarea></div>
            <div><label class="label" for="nt">{{ t('orders.notes') }}</label><textarea id="nt" v-model="form.notes" rows="2" class="field"></textarea></div>
            <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary">{{ t('common.save') }}</OfflineGuard>
        </form>
        <ul class="space-y-2"><li v-for="o in orders" :key="o.id"><Link :href="`/app/orders/${o.id}`" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3 text-sm"><span>{{ o.order_number }} · {{ formatBdt(o.total) }}</span><StatusBadge>{{ t(`orders.status_${o.status}`) }}</StatusBadge></Link></li></ul>
    </div>
</template>
