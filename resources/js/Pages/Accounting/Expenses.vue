<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Pagination from '@/Components/Pagination.vue';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ expenses: any; month_total: string; categories: { id: number; name: string }[]; financial: { id: number; name: string }[]; filters: { category?: string }; today: string; canManage: boolean }>();
const { t } = useI18n();
const open = ref(false);
// One idempotency key per submit intent: a replay after a dropped connection cannot double-post (OFF-8).
const form = useForm({ idempotency_key: crypto.randomUUID(), expense_date: props.today, expense_category_id: props.categories[0]?.id, amount: '', financial_account_id: props.financial[0]?.id, vendor: '', reference: '', description: '', receipt: null as File | null });
const submit = () => form.post('/app/accounting/expenses', { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset('amount', 'vendor', 'reference', 'description', 'receipt'); form.idempotency_key = crypto.randomUUID(); open.value = false; } });
const voidIt = (id: number) => window.confirm(t('acct.confirmVoid')) && router.post(`/app/accounting/expenses/${id}/void`, {}, { preserveScroll: true });
const err = (k: string) => (form.errors as Record<string, string>)[k];
const filter = (e: Event) => router.get('/app/accounting/expenses', { category: (e.target as HTMLSelectElement).value || undefined }, { preserveState: true, replace: true });
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.expenses') }}</h1></div>
            <button v-if="canManage" type="button" class="btn-primary" data-testid="add-expense" @click="open = true"><NavIcon name="plus" :size="16" /> {{ t('acct.addExpense') }}</button></div>
        <AccountingNav />
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-danger/12 text-danger"><NavIcon name="wallet" /></span><div><p class="eyebrow">{{ t('acct.monthSpend') }}</p><p class="text-2xl font-bold tabular">{{ formatBdt(month_total) }}</p></div></div>
            <select class="field" :value="filters.category ?? ''" :aria-label="t('acct.category')" @change="filter"><option value="">{{ t('acct.category') }}: {{ t('common.all') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <p v-if="!expenses.data.length" class="card p-8 text-center text-sm text-muted">{{ t('acct.noExpenses') }}</p>
        <ul class="space-y-2">
            <li v-for="e in expenses.data" :key="e.id" class="card flex items-center justify-between gap-3 p-3.5" :class="e.status === 'void' ? 'opacity-60' : ''" data-testid="expense-row">
                <div class="min-w-0"><p class="truncate font-medium" :class="e.status === 'void' ? 'line-through' : ''">{{ e.category }}<span v-if="e.vendor" class="font-normal text-muted"> · {{ e.vendor }}</span></p>
                    <p class="text-xs text-muted">{{ e.date }} · {{ e.account }}<span v-if="e.reference"> · {{ e.reference }}</span></p></div>
                <div class="flex shrink-0 items-center gap-3"><span class="font-semibold tabular">{{ formatBdt(e.amount) }}</span>
                    <a v-if="e.has_receipt" :href="`/app/accounting/expenses/${e.id}/receipt`" target="_blank" rel="noopener" class="link-action" data-testid="receipt-link">{{ t('acct.viewReceipt') }}</a>
                    <StatusBadge v-if="e.status === 'void'" tone="muted">{{ t('acct.voided') }}</StatusBadge>
                    <OfflineGuard v-else-if="canManage" button-class="btn-ghost !px-3" @click="voidIt(e.id)">{{ t('acct.void') }}</OfflineGuard></div>
            </li>
        </ul>
        <Pagination :meta="expenses" />

        <Sheet :open="open" :title="t('acct.addExpense')" @close="open = false">
            <form class="space-y-4" novalidate @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="ed">{{ t('acct.date') }}</label><input id="ed" v-model="form.expense_date" type="date" class="field" :max="today" /><p v-if="err('expense_date')" class="err" role="alert">{{ err('expense_date') }}</p></div>
                    <div><label class="label" for="ea">{{ t('acct.amount') }}</label><input id="ea" v-model="form.amount" class="field !text-lg" inputmode="decimal" /><p v-if="err('amount')" class="err" role="alert">{{ err('amount') }}</p></div>
                </div>
                <div><label class="label" for="ec">{{ t('acct.category') }}</label><select id="ec" v-model="form.expense_category_id" class="field"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                <div><label class="label" for="ef">{{ t('acct.paidFrom') }}</label><select id="ef" v-model="form.financial_account_id" class="field"><option v-for="f in financial" :key="f.id" :value="f.id">{{ f.name }}</option></select></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="ev">{{ t('acct.vendor') }}</label><input id="ev" v-model="form.vendor" class="field" /></div><div><label class="label" for="er">{{ t('acct.reference') }}</label><input id="er" v-model="form.reference" class="field" /></div></div>
                <div><label class="label" for="ex">{{ t('acct.description') }}</label><input id="ex" v-model="form.description" class="field" maxlength="500" /></div>
                <div><label class="label" for="erc">{{ t('acct.attachReceipt') }}</label>
                    <!-- On phones this opens the camera or gallery (UI-16) -->
                    <input id="erc" type="file" class="field" accept="image/*,application/pdf" capture="environment" data-testid="receipt-input" @change="form.receipt = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <p v-if="err('receipt')" class="err" role="alert">{{ err('receipt') }}</p></div>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('acct.save') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
