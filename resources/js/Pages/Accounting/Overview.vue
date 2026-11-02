<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{
    cash_total: string; financial_accounts: { id: number; name: string; type: string; balance: string }[]; cod_receivable: string;
    inventory: { ledger: string; stock: string }; month: { from: string; to: string; income: string; cogs: string; expenses: string; net: string } | null;
    locked_through: string | null; recent: { id: number; entry_number: string; transaction_date: string; memo: string; source_type: string }[]; canPost: boolean;
}>();
const { t } = useI18n();
const matches = props.inventory.ledger === props.inventory.stock;
const typeIcon: Record<string, string> = { cash: 'coins', bank: 'building', mfs: 'zap' };
</script>

<template>
    <div class="mx-auto max-w-[88rem]">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.overview') }}</h1></div>
            <StatusBadge :tone="locked_through ? 'warn' : 'ok'"><NavIcon :name="locked_through ? 'lock' : 'shield'" :size="12" class="mr-1 inline" />{{ locked_through ? t('acct.locked', { d: locked_through }) : t('acct.open') }}</StatusBadge></div>
        <AccountingNav />

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard :label="t('acct.cashTotal')" :value="formatBdt(cash_total)" icon="wallet" tone="brand" />
            <StatCard :label="t('acct.receivable')" :value="formatBdt(cod_receivable)" icon="truck" tone="info" href="/app/accounting/cod" />
            <StatCard :label="t('acct.inventory')" :value="formatBdt(inventory.ledger)" icon="stack" :tone="matches ? 'ok' : 'warn'" :hint="matches ? t('acct.stockMatches') : t('acct.stockDiffers', { v: formatBdt(inventory.stock) })" />
            <StatCard v-if="month" :label="`${t('acct.net')} · ${t('acct.thisMonth')}`" :value="formatBdt(month.net)" icon="coins" :tone="Number(month.net) >= 0 ? 'ok' : 'danger'" href="/app/reports/profit-loss" />
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-3">
            <section class="card p-5">
                <h2 class="mb-3 font-semibold">{{ t('acct.cashTotal') }}</h2>
                <ul class="space-y-2"><li v-for="f in financial_accounts" :key="f.id" class="flex items-center justify-between gap-3 rounded-xl bg-surface-2 px-3 py-2.5 text-sm">
                    <span class="flex items-center gap-2.5"><span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-soft text-brand"><NavIcon :name="typeIcon[f.type] ?? 'coins'" :size="16" /></span>{{ f.name }}</span>
                    <span class="font-semibold tabular" :class="Number(f.balance) < 0 ? 'text-danger' : ''">{{ formatBdt(f.balance) }}</span></li></ul>
                <Link v-if="canPost" href="/app/accounting/accounts" class="link-action mt-1 gap-1"><NavIcon name="plus" :size="14" /> {{ t('acct.addAccount') }}</Link>
            </section>

            <section v-if="month" class="card p-5">
                <h2 class="mb-3 font-semibold">{{ t('acct.thisMonth') }}</h2>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ t('acct.income') }}</dt><dd class="font-semibold tabular">{{ formatBdt(month.income) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ t('acct.cogs') }}</dt><dd class="font-semibold tabular">{{ formatBdt(month.cogs) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ t('acct.expensesLabel') }}</dt><dd class="font-semibold tabular">{{ formatBdt(month.expenses) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2.5 text-base"><dt class="font-semibold">{{ t('acct.net') }}</dt><dd class="font-bold tabular" :class="Number(month.net) >= 0 ? 'text-ok' : 'text-danger'">{{ formatBdt(month.net) }}</dd></div>
                </dl>
            </section>

            <section class="card p-5" :class="month ? '' : 'xl:col-span-2'">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ t('acct.recent') }}</h2><Link href="/app/accounting/journal" class="link-action">{{ t('dash.viewAll') }}</Link></div>
                <ul v-if="recent.length" class="divide-y divide-line"><li v-for="e in recent" :key="e.id" class="py-2.5 text-sm"><p class="truncate font-medium">{{ e.memo }}</p><p class="text-xs text-muted">{{ e.entry_number }} · {{ e.transaction_date?.slice(0, 10) }}</p></li></ul>
                <p v-else class="py-6 text-center text-sm text-muted">{{ t('acct.noActivity') }}</p>
            </section>
        </div>
    </div>
</template>
