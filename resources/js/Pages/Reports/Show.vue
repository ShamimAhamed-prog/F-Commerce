<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ type: string; data: any; from: string; to: string; generated_at: string; accounts: { id: number; code: string; name: string }[]; account: number | null; available: string[] }>();
const { t } = useI18n();
const from = ref(props.from);
const to = ref(props.to);
const acct = ref(props.account);
const needsFrom = computed(() => ['profit-loss', 'cash-flow', 'ledger', 'expense-summary', 'owner-equity', 'tax-summary'].includes(props.type));
const csvHref = computed(() => `/app/reports/${props.type}?format=csv&from=${from.value}&to=${to.value}${props.type === 'ledger' && acct.value ? `&account=${acct.value}` : ''}`);
const run = () => router.get(`/app/reports/${props.type}`, { from: needsFrom.value ? from.value : undefined, to: to.value, account: props.type === 'ledger' ? acct.value : undefined }, { preserveState: true, replace: true });
const money = (v: string) => formatBdt(v);
const print = () => window.print();
const generated = computed(() => new Date(props.generated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
const q = computed(() => `?from=${from.value}&to=${to.value}`);
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3 print:hidden"><div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t(`acct.${type}`) }}</h1></div>
            <div class="flex gap-2"><a :href="csvHref" class="btn-secondary" download data-testid="export-csv"><NavIcon name="download" :size="16" /> {{ t('acct.exportCsv') }}</a>
                <button type="button" class="btn-secondary" @click="print">{{ t('acct.print') }}</button></div></div>
        <AccountingNav class="print:hidden" />

        <nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0 print:hidden" aria-label="Reports"><ul class="inline-flex gap-1.5">
            <li v-for="r in available" :key="r"><Link :href="`/app/reports/${r}${q}`" class="block whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm transition" :class="r === type ? 'border-brand bg-brand-soft font-semibold text-brand' : 'border-line text-muted hover:text-ink'">{{ t(`acct.${r}`) }}</Link></li></ul></nav>

        <form class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden" novalidate @submit.prevent="run">
            <div v-if="type === 'ledger'" class="min-w-[12rem] flex-1"><label class="label" for="ra">{{ t('acct.pickAccount') }}</label><select id="ra" v-model="acct" class="field"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></select></div>
            <div v-if="needsFrom"><label class="label" for="rf">{{ t('acct.from') }}</label><input id="rf" v-model="from" type="date" class="field" /></div>
            <div><label class="label" for="rt">{{ t('acct.to') }}</label><input id="rt" v-model="to" type="date" class="field" /></div>
            <button type="submit" class="btn-primary">{{ t('acct.run') }}</button>
            <p class="ml-auto text-xs text-muted">{{ t('acct.generated', { t: generated }) }}</p>
        </form>

        <!-- Trial balance -->
        <section v-if="type === 'trial-balance'" class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-line px-5 py-3"><p class="text-sm text-muted">As of {{ data.as_of }}</p><StatusBadge :tone="data.balanced ? 'ok' : 'danger'">{{ data.balanced ? t('acct.balanced') : t('acct.notBalanced') }}</StatusBadge></div>
            <table class="w-full text-sm"><thead class="text-left text-xs text-muted"><tr><th class="px-5 py-2">{{ t('acct.code') }}</th><th class="py-2">{{ t('acct.name') }}</th><th class="py-2 text-right">{{ t('acct.debit') }}</th><th class="px-5 py-2 text-right">{{ t('acct.credit') }}</th></tr></thead>
                <tbody><tr v-for="r in data.rows" :key="r.code" class="border-t border-line"><td class="px-5 py-2 text-muted">{{ r.code }}</td><td class="py-2">{{ r.name }}</td><td class="py-2 text-right tabular">{{ Number(r.debit) ? money(r.debit) : '' }}</td><td class="px-5 py-2 text-right tabular">{{ Number(r.credit) ? money(r.credit) : '' }}</td></tr></tbody>
                <tfoot><tr class="border-t-2 border-line font-bold"><td class="px-5 py-3" colspan="2">{{ t('acct.totals') }}</td><td class="py-3 text-right tabular">{{ money(data.total_debit) }}</td><td class="px-5 py-3 text-right tabular">{{ money(data.total_credit) }}</td></tr></tfoot></table>
        </section>

        <!-- Profit & loss -->
        <section v-else-if="type === 'profit-loss'" class="space-y-4">
            <div class="grid grid-cols-3 gap-3"><div class="card p-4"><p class="eyebrow">{{ t('acct.totalIncome') }}</p><p class="mt-1 text-lg font-bold tabular sm:text-2xl">{{ money(data.total_income) }}</p></div>
                <div class="card p-4"><p class="eyebrow">{{ t('acct.grossProfit') }}</p><p class="mt-1 text-lg font-bold tabular sm:text-2xl">{{ money(data.gross_profit) }}</p></div>
                <div class="card p-4"><p class="eyebrow">{{ t('acct.net') }}</p><p class="mt-1 text-lg font-bold tabular sm:text-2xl" :class="Number(data.net_profit) >= 0 ? 'text-ok' : 'text-danger'">{{ money(data.net_profit) }}</p></div></div>
            <div class="card overflow-hidden">
                <template v-for="sec in [{ k: 'income', title: 'acct.income', total: 'total_income', tt: 'acct.totalIncome' }, { k: 'cogs', title: 'acct.cogs', total: 'total_cogs', tt: 'acct.totalCogs' }, { k: 'expenses', title: 'acct.expensesLabel', total: 'total_expenses', tt: 'acct.totalExpenses' }]" :key="sec.k">
                    <h2 class="border-y border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold first:border-t-0">{{ t(sec.title) }}</h2>
                    <table class="w-full text-sm"><tbody><tr v-for="r in data[sec.k]" :key="r.code"><td class="w-20 px-5 py-1.5 text-muted">{{ r.code }}</td><td class="py-1.5">{{ r.name }}</td><td class="px-5 py-1.5 text-right tabular" :class="Number(r.amount) < 0 ? 'text-danger' : ''">{{ money(r.amount) }}</td></tr>
                        <tr v-if="!data[sec.k].length"><td class="px-5 py-2 text-muted" colspan="3">{{ t('acct.noActivity') }}</td></tr>
                        <tr class="border-t border-line font-semibold"><td class="px-5 py-2" colspan="2">{{ t(sec.tt) }}</td><td class="px-5 py-2 text-right tabular">{{ money(data[sec.total]) }}</td></tr></tbody></table>
                </template>
                <div class="flex justify-between bg-brand-soft px-5 py-3.5 text-base font-bold"><span>{{ t('acct.net') }}</span><span class="tabular" :class="Number(data.net_profit) >= 0 ? 'text-ok' : 'text-danger'">{{ money(data.net_profit) }}</span></div>
            </div>
        </section>

        <!-- Balance sheet -->
        <section v-else-if="type === 'balance-sheet'" class="space-y-4">
            <div class="flex items-center justify-between"><p class="text-sm text-muted">As of {{ data.as_of }}</p><StatusBadge :tone="data.balanced ? 'ok' : 'danger'">{{ data.balanced ? t('acct.balanced') : t('acct.notBalanced') }}</StatusBadge></div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card overflow-hidden"><h2 class="border-b border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.assets') }}</h2>
                    <table class="w-full text-sm"><tbody><tr v-for="r in data.assets" :key="r.code"><td class="px-5 py-1.5">{{ r.name }}</td><td class="px-5 py-1.5 text-right tabular">{{ money(r.amount) }}</td></tr><tr class="border-t border-line font-bold"><td class="px-5 py-2.5">{{ t('acct.assets') }}</td><td class="px-5 py-2.5 text-right tabular">{{ money(data.total_assets) }}</td></tr></tbody></table></div>
                <div class="space-y-4">
                    <div class="card overflow-hidden"><h2 class="border-b border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.liabilities') }}</h2>
                        <table class="w-full text-sm"><tbody><tr v-for="r in data.liabilities" :key="r.code"><td class="px-5 py-1.5">{{ r.name }}</td><td class="px-5 py-1.5 text-right tabular">{{ money(r.amount) }}</td></tr><tr class="border-t border-line font-bold"><td class="px-5 py-2.5">{{ t('acct.liabilities') }}</td><td class="px-5 py-2.5 text-right tabular">{{ money(data.total_liabilities) }}</td></tr></tbody></table></div>
                    <div class="card overflow-hidden"><h2 class="border-b border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.equityTotal') }}</h2>
                        <table class="w-full text-sm"><tbody><tr v-for="r in data.equity" :key="r.name"><td class="px-5 py-1.5">{{ r.name }}</td><td class="px-5 py-1.5 text-right tabular" :class="Number(r.amount) < 0 ? 'text-danger' : ''">{{ money(r.amount) }}</td></tr><tr class="border-t border-line font-bold"><td class="px-5 py-2.5">{{ t('acct.equityTotal') }}</td><td class="px-5 py-2.5 text-right tabular">{{ money(data.total_equity) }}</td></tr></tbody></table></div>
                </div>
            </div>
        </section>

        <!-- Cash flow (direct) -->
        <section v-else-if="type === 'cash-flow'" class="card overflow-hidden">
            <div class="flex justify-between border-b border-line px-5 py-3 text-sm"><span class="text-muted">{{ t('acct.opening') }}</span><span class="font-semibold tabular">{{ money(data.opening) }}</span></div>
            <h2 class="bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.inflows') }}</h2>
            <table class="w-full text-sm"><tbody><tr v-for="r in data.inflows" :key="r.label"><td class="px-5 py-1.5">{{ r.label }}</td><td class="px-5 py-1.5 text-right tabular text-ok">{{ money(r.amount) }}</td></tr><tr v-if="!data.inflows.length"><td class="px-5 py-2 text-muted">{{ t('acct.noActivity') }}</td></tr></tbody></table>
            <h2 class="border-t border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.outflows') }}</h2>
            <table class="w-full text-sm"><tbody><tr v-for="r in data.outflows" :key="r.label"><td class="px-5 py-1.5">{{ r.label }}</td><td class="px-5 py-1.5 text-right tabular text-danger">-{{ money(r.amount) }}</td></tr><tr v-if="!data.outflows.length"><td class="px-5 py-2 text-muted">{{ t('acct.noActivity') }}</td></tr></tbody></table>
            <div class="flex justify-between border-t border-line bg-brand-soft px-5 py-3.5 font-bold"><span>{{ t('acct.closing') }}</span><span class="tabular">{{ money(data.closing) }}</span></div>
        </section>

        <!-- Expenses & ad spend -->
        <section v-else-if="type === 'expense-summary'" class="space-y-4">
            <div class="grid grid-cols-2 gap-3"><div class="card p-4"><p class="eyebrow">{{ t('acct.totalSpend') }}</p><p class="mt-1 text-2xl font-bold tabular">{{ money(data.total) }}</p></div>
                <div class="card p-4"><p class="eyebrow">{{ t('acct.advertising') }}</p><p class="mt-1 text-2xl font-bold tabular">{{ money(data.advertising) }}</p></div></div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div v-for="g in [{ k: 'by_category', t: 'acct.byCategory' }, { k: 'by_month', t: 'acct.byMonth' }, { k: 'top_vendors', t: 'acct.topVendors' }, { k: 'ad_platforms', t: 'acct.adPlatforms' }]" :key="g.k" class="card overflow-hidden">
                    <h2 class="border-b border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t(g.t) }}</h2>
                    <table class="w-full text-sm"><tbody><tr v-for="r in data[g.k]" :key="r.label"><td class="px-5 py-1.5">{{ r.label }}</td><td class="px-5 py-1.5 text-right tabular">{{ money(r.amount) }}</td></tr><tr v-if="!data[g.k].length"><td class="px-5 py-3 text-muted">{{ t('acct.noActivity') }}</td></tr></tbody></table>
                </div>
            </div>
        </section>

        <!-- Owner equity -->
        <section v-else-if="type === 'owner-equity'" class="card overflow-hidden">
            <table class="w-full text-sm"><tbody>
                <tr v-for="r in [['acct.capitalIn', data.capital], ['acct.drawingsOut', data.drawings], ['acct.netFunding', data.net_owner_funding], ['acct.loansReceived', data.loans_received], ['acct.loansPayable', data.loans_payable]]" :key="r[0]" class="border-b border-line last:border-0"><td class="px-5 py-3">{{ t(r[0]) }}</td><td class="px-5 py-3 text-right font-semibold tabular">{{ money(r[1]) }}</td></tr></tbody></table>
        </section>

        <!-- Tax / VAT -->
        <section v-else-if="type === 'tax-summary'" class="space-y-3">
            <p class="rounded-xl border border-warn/30 bg-warn/10 px-4 py-2.5 text-sm">{{ t('acct.taxDraft') }}</p>
            <div class="card overflow-hidden"><table class="w-full text-sm"><tbody>
                <tr v-for="r in [['acct.taxOpening', data.opening], ['acct.taxCollected', data.collected], ['acct.taxAdjusted', data.adjusted], ['acct.taxClosing', data.closing]]" :key="r[0]" class="border-b border-line last:border-0"><td class="px-5 py-3">{{ t(r[0]) }}</td><td class="px-5 py-3 text-right font-semibold tabular">{{ money(r[1]) }}</td></tr></tbody></table></div>
            <div v-if="data.by_month.length" class="card overflow-hidden"><h2 class="border-b border-line bg-surface-2/60 px-5 py-2 text-sm font-semibold">{{ t('acct.byMonth') }}</h2>
                <table class="w-full text-sm"><tbody><tr v-for="r in data.by_month" :key="r.label"><td class="px-5 py-1.5">{{ r.label }}</td><td class="px-5 py-1.5 text-right tabular">{{ money(r.amount) }}</td></tr></tbody></table></div>
        </section>

        <!-- General ledger -->
        <section v-else-if="type === 'ledger'" class="card overflow-x-auto">
            <div class="flex justify-between border-b border-line px-5 py-3 text-sm"><span class="font-semibold">{{ data.account.code }} · {{ data.account.name }}</span><span class="text-muted">{{ t('acct.opening') }} <strong class="tabular text-ink">{{ money(data.opening) }}</strong></span></div>
            <table class="w-full min-w-[34rem] text-sm"><thead class="text-left text-xs text-muted"><tr><th class="sticky left-0 bg-card px-5 py-2">{{ t('acct.date') }}</th><th class="py-2">{{ t('acct.memo') }}</th><th class="py-2 text-right">{{ t('acct.debit') }}</th><th class="py-2 text-right">{{ t('acct.credit') }}</th><th class="px-5 py-2 text-right">{{ t('acct.balance') }}</th></tr></thead>
                <tbody><tr v-for="(l, i) in data.lines" :key="i" class="border-t border-line"><td class="sticky left-0 bg-card px-5 py-2 text-muted">{{ l.date }}<span class="block text-[11px]">{{ l.entry_number }}</span></td><td class="py-2">{{ l.memo }}</td><td class="py-2 text-right tabular">{{ Number(l.debit) ? money(l.debit) : '' }}</td><td class="py-2 text-right tabular">{{ Number(l.credit) ? money(l.credit) : '' }}</td><td class="px-5 py-2 text-right font-medium tabular">{{ money(l.balance) }}</td></tr>
                    <tr v-if="!data.lines.length"><td class="px-5 py-6 text-center text-muted" colspan="5">{{ t('acct.noActivity') }}</td></tr></tbody>
                <tfoot><tr class="border-t-2 border-line font-bold"><td class="px-5 py-3" colspan="4">{{ t('acct.closing') }}</td><td class="px-5 py-3 text-right tabular">{{ money(data.closing) }}</td></tr></tfoot></table>
        </section>
    </div>
</template>
