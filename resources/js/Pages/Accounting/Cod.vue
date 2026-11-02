<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ receivables: any[]; balance: string; settlements: any[]; financial: { id: number; name: string }[]; today: string; canPost: boolean }>();
const { t } = useI18n();
const target = ref<any | null>(null);
const form = useForm({ idempotency_key: crypto.randomUUID(), order_id: 0, settled_date: props.today, received: '', fee: '', financial_account_id: props.financial[0]?.id, reference: '' });
function open(r: any) { target.value = r; form.order_id = r.id; form.received = r.outstanding; form.fee = ''; }
const submit = () => form.post('/app/accounting/cod', { preserveScroll: true, onSuccess: () => { target.value = null; form.idempotency_key = crypto.randomUUID(); } });
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.cod') }}</h1><p class="mt-1 max-w-xl text-sm text-muted">{{ t('acct.codHelp') }}</p></div>
            <div class="card px-5 py-3 text-right"><p class="eyebrow">{{ t('acct.receivable') }}</p><p class="text-2xl font-bold tabular">{{ formatBdt(balance) }}</p></div></div>
        <AccountingNav />
        <p v-if="!receivables.length" class="card p-8 text-center text-sm text-muted">{{ t('acct.noReceivables') }}</p>
        <ul class="space-y-2">
            <li v-for="r in receivables" :key="r.id" class="card flex flex-wrap items-center justify-between gap-3 p-3.5" data-testid="cod-row">
                <div class="min-w-0"><p class="font-medium">{{ r.order_number }} <span class="font-normal text-muted">· {{ r.customer_name }}</span></p>
                    <p class="text-xs text-muted">{{ t(`orders.status_${r.status}`) }} · {{ t('acct.cleared') }} {{ formatBdt(r.cleared) }} / {{ formatBdt(r.cod_amount) }}</p></div>
                <div class="flex items-center gap-3"><span class="font-semibold tabular">{{ formatBdt(r.outstanding) }}</span>
                    <StatusBadge v-if="r.status !== 'delivered'" tone="muted">{{ t(`orders.status_${r.status}`) }}</StatusBadge>
                    <button v-else-if="canPost" type="button" class="btn-primary !px-3" data-testid="settle" @click="open(r)">{{ t('acct.settle') }}</button></div>
            </li>
        </ul>
        <section v-if="settlements.length" class="mt-6"><h2 class="mb-2 font-semibold">{{ t('acct.history') }}</h2>
            <ul class="card divide-y divide-line"><li v-for="s in settlements" :key="s.id" class="flex flex-wrap justify-between gap-2 p-3.5 text-sm"><span>{{ s.order }} · {{ s.date }} · {{ s.account }}<span v-if="s.reference" class="text-muted"> · {{ s.reference }}</span></span>
                <span class="tabular"><span class="font-semibold text-ok">+{{ formatBdt(s.received) }}</span><span v-if="Number(s.fee)" class="text-muted"> (fee {{ formatBdt(s.fee) }})</span></span></li></ul></section>
        <Sheet :open="!!target" :title="t('acct.settle')" @close="target = null">
            <form v-if="target" class="space-y-4" novalidate @submit.prevent="submit">
                <p class="text-sm"><strong>{{ target.order_number }}</strong> · {{ t('acct.outstanding') }} {{ formatBdt(target.outstanding) }}</p>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="cr">{{ t('acct.received') }}</label><input id="cr" v-model="form.received" class="field !text-lg" inputmode="decimal" /><p v-if="err('received')" class="err" role="alert">{{ err('received') }}</p></div>
                    <div><label class="label" for="cf">{{ t('acct.fee') }}</label><input id="cf" v-model="form.fee" class="field !text-lg" inputmode="decimal" /></div></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="cd">{{ t('acct.settledOn') }}</label><input id="cd" v-model="form.settled_date" type="date" class="field" :max="today" /><p v-if="err('settled_date')" class="err" role="alert">{{ err('settled_date') }}</p></div>
                    <div><label class="label" for="ca">{{ t('acct.paidFrom') }}</label><select id="ca" v-model="form.financial_account_id" class="field"><option v-for="f in financial" :key="f.id" :value="f.id">{{ f.name }}</option></select></div></div>
                <div><label class="label" for="cref">{{ t('acct.reference') }}</label><input id="cref" v-model="form.reference" class="field" /></div>
                <p v-if="err('order_id')" class="err" role="alert">{{ err('order_id') }}</p>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('acct.settle') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
