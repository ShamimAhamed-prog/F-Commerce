<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ accounts: { id: number; code: string; name: string; type: string; balance: string }[]; financial: { id: number; name: string; type: string; code: string }[]; locked_through: string | null; today: string; canPost: boolean; canClose: boolean }>();
const { t } = useI18n();
const groups = computed(() => ['asset', 'liability', 'equity', 'income', 'cogs', 'expense'].map((k) => ({ k, rows: props.accounts.filter((a) => a.type === k) })).filter((g) => g.rows.length));
const typeLabel: Record<string, string> = { asset: 'asset', liability: 'liability', equity: 'equityType', income: 'incomeType', cogs: 'cogsType', expense: 'expenseType' };
const fa = useForm({ name: '', type: 'bank' });
const close = useForm({ through: props.today });
const addFa = () => fa.post('/app/accounting/financial-accounts', { preserveScroll: true, onSuccess: () => fa.reset('name') });
const closeBooks = () => window.confirm(t('acct.closeHelp')) && close.post('/app/accounting/close-period', { preserveScroll: true });
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-5">
        <div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.accounts') }}</h1></div>
        <AccountingNav />
        <div class="grid gap-4 lg:grid-cols-2">
            <form v-if="canPost" class="card space-y-3 p-5" novalidate @submit.prevent="addFa">
                <h2 class="font-semibold">{{ t('acct.addAccount') }}</h2>
                <div><label class="label" for="fn">{{ t('acct.accountName') }}</label><input id="fn" v-model="fa.name" class="field" placeholder="e.g. bKash Merchant" /><p v-if="fa.errors.name" class="err" role="alert">{{ fa.errors.name }}</p></div>
                <div><label class="label" for="ft">{{ t('acct.accountType') }}</label><select id="ft" v-model="fa.type" class="field"><option value="cash">{{ t('acct.cash') }}</option><option value="bank">{{ t('acct.bank') }}</option><option value="mfs">{{ t('acct.mfs') }}</option></select></div>
                <OfflineGuard type="submit" :pending="fa.processing" button-class="btn-primary">{{ t('acct.save') }}</OfflineGuard>
            </form>
            <form v-if="canClose" class="card space-y-3 p-5" novalidate @submit.prevent="closeBooks">
                <div class="flex items-center justify-between"><h2 class="font-semibold">{{ t('acct.closePeriod') }}</h2><StatusBadge :tone="locked_through ? 'warn' : 'ok'"><NavIcon :name="locked_through ? 'lock' : 'shield'" :size="12" class="mr-1 inline" />{{ locked_through ? t('acct.locked', { d: locked_through }) : t('acct.open') }}</StatusBadge></div>
                <p class="text-sm text-muted">{{ t('acct.closeHelp') }}</p>
                <div><label class="label" for="ct">{{ t('acct.closeThrough') }}</label><input id="ct" v-model="close.through" type="date" class="field" :max="today" /><p v-if="close.errors.through" class="err" role="alert">{{ close.errors.through }}</p></div>
                <OfflineGuard type="submit" :pending="close.processing" button-class="btn-secondary">{{ t('acct.closePeriod') }}</OfflineGuard>
            </form>
        </div>
        <section v-for="g in groups" :key="g.k" class="card overflow-hidden">
            <h2 class="border-b border-line bg-surface-2/60 px-5 py-2.5 text-sm font-semibold">{{ t(`acct.${typeLabel[g.k]}`) }}</h2>
            <table class="w-full text-sm"><tbody><tr v-for="a in g.rows" :key="a.id" class="border-b border-line last:border-0"><td class="w-20 px-5 py-2 text-muted tabular">{{ a.code }}</td><td class="py-2">{{ a.name }}</td><td class="px-5 py-2 text-right font-medium tabular" :class="Number(a.balance) < 0 ? 'text-danger' : ''">{{ formatBdt(a.balance) }}</td></tr></tbody></table>
        </section>
    </div>
</template>
