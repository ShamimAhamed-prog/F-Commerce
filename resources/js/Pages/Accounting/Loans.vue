<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ loans: any[]; financial: { id: number; name: string }[]; today: string; canPost: boolean }>();
const { t } = useI18n();
const newLoan = ref(false);
const repaying = ref<any | null>(null);
const loan = useForm({ idempotency_key: crypto.randomUUID(), lender: '', principal: '', received_date: props.today, financial_account_id: props.financial[0]?.id, note: '' });
const pay = useForm({ idempotency_key: crypto.randomUUID(), tx_date: props.today, principal: '', interest: '', financial_account_id: props.financial[0]?.id, note: '' });
const saveLoan = () => loan.post('/app/accounting/loans', { preserveScroll: true, onSuccess: () => { loan.reset('lender', 'principal', 'note'); loan.idempotency_key = crypto.randomUUID(); newLoan.value = false; } });
const savePay = () => pay.post(`/app/accounting/loans/${repaying.value.id}/repay`, { preserveScroll: true, onSuccess: () => { pay.reset('principal', 'interest', 'note'); pay.idempotency_key = crypto.randomUUID(); repaying.value = null; } });
const e1 = (k: string) => (loan.errors as Record<string, string>)[k];
const e2 = (k: string) => (pay.errors as Record<string, string>)[k];
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.loans') }}</h1><p class="mt-1 text-sm text-muted">{{ t('acct.loanHelp') }}</p></div>
            <button v-if="canPost" type="button" class="btn-primary" data-testid="add-loan" @click="newLoan = true"><NavIcon name="plus" :size="16" /> {{ t('acct.addLoan') }}</button></div>
        <AccountingNav />
        <p v-if="!loans.length" class="card p-8 text-center text-sm text-muted">{{ t('acct.noLoans') }}</p>
        <ul class="space-y-3">
            <li v-for="l in loans" :key="l.id" class="card p-4" data-testid="loan-row">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><p class="font-semibold">{{ l.lender }}</p><p class="text-xs text-muted">{{ l.received_date }} · {{ l.account }}<span v-if="l.note"> · {{ l.note }}</span></p></div>
                    <div class="flex items-center gap-3 text-right"><div><p class="eyebrow">{{ t('acct.principal') }}</p><p class="font-semibold tabular">{{ formatBdt(l.principal) }}</p></div>
                        <div><p class="eyebrow">{{ t('acct.outstandingLoan') }}</p><p class="font-bold tabular" :class="Number(l.outstanding) > 0 ? 'text-warn' : 'text-ok'">{{ formatBdt(l.outstanding) }}</p></div>
                        <button v-if="canPost && Number(l.outstanding) > 0" type="button" class="btn-secondary !px-3" @click="repaying = l">{{ t('acct.repay') }}</button>
                        <StatusBadge v-else-if="Number(l.outstanding) <= 0" tone="ok">Paid off</StatusBadge></div>
                </div>
                <ul v-if="l.repayments.length" class="mt-3 space-y-1 border-t border-line pt-3 text-sm text-muted"><li v-for="r in l.repayments" :key="r.id" class="flex justify-between"><span>{{ r.date }}</span><span class="tabular">{{ formatBdt(r.principal) }}<span v-if="Number(r.interest)"> + {{ formatBdt(r.interest) }} {{ t('acct.interest').toLowerCase() }}</span></span></li></ul>
            </li>
        </ul>

        <Sheet :open="newLoan" :title="t('acct.addLoan')" @close="newLoan = false">
            <form class="space-y-4" novalidate @submit.prevent="saveLoan">
                <div><label class="label" for="ll">{{ t('acct.lender') }}</label><input id="ll" v-model="loan.lender" class="field" /><p v-if="e1('lender')" class="err" role="alert">{{ e1('lender') }}</p></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="lp">{{ t('acct.amount') }}</label><input id="lp" v-model="loan.principal" class="field !text-lg" inputmode="decimal" /><p v-if="e1('principal')" class="err" role="alert">{{ e1('principal') }}</p></div>
                    <div><label class="label" for="ld">{{ t('acct.date') }}</label><input id="ld" v-model="loan.received_date" type="date" class="field" :max="today" /><p v-if="e1('received_date')" class="err" role="alert">{{ e1('received_date') }}</p></div></div>
                <div><label class="label" for="la">Received into</label><select id="la" v-model="loan.financial_account_id" class="field"><option v-for="f in financial" :key="f.id" :value="f.id">{{ f.name }}</option></select></div>
                <div><label class="label" for="ln">{{ t('acct.note') }}</label><input id="ln" v-model="loan.note" class="field" maxlength="300" /></div>
                <OfflineGuard type="submit" :pending="loan.processing" button-class="btn-primary w-full">{{ t('acct.save') }}</OfflineGuard>
            </form>
        </Sheet>
        <Sheet :open="!!repaying" :title="t('acct.repay')" @close="repaying = null">
            <form v-if="repaying" class="space-y-4" novalidate @submit.prevent="savePay">
                <p class="text-sm"><strong>{{ repaying.lender }}</strong> · {{ t('acct.outstandingLoan') }} {{ formatBdt(repaying.outstanding) }}</p>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="rp">{{ t('acct.principal') }}</label><input id="rp" v-model="pay.principal" class="field !text-lg" inputmode="decimal" /><p v-if="e2('principal')" class="err" role="alert">{{ e2('principal') }}</p></div>
                    <div><label class="label" for="ri">{{ t('acct.interest') }}</label><input id="ri" v-model="pay.interest" class="field !text-lg" inputmode="decimal" /></div></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="rd">{{ t('acct.date') }}</label><input id="rd" v-model="pay.tx_date" type="date" class="field" :max="today" /><p v-if="e2('tx_date')" class="err" role="alert">{{ e2('tx_date') }}</p></div>
                    <div><label class="label" for="ra">{{ t('acct.paidFrom') }}</label><select id="ra" v-model="pay.financial_account_id" class="field"><option v-for="f in financial" :key="f.id" :value="f.id">{{ f.name }}</option></select></div></div>
                <OfflineGuard type="submit" :pending="pay.processing" button-class="btn-primary w-full">{{ t('acct.save') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
