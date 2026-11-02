<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Sheet from '@/Components/Sheet.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ contributions: any[]; drawings: any[]; financial: { id: number; name: string }[]; today: string; canPost: boolean }>();
const { t } = useI18n();
const kind = ref<'capital' | 'drawing' | null>(null);
const form = useForm({ idempotency_key: crypto.randomUUID(), date: props.today, amount: '', financial_account_id: props.financial[0]?.id, note: '' });
const submit = () => form.post(`/app/accounting/equity/${kind.value}`, { preserveScroll: true, onSuccess: () => { form.reset('amount', 'note'); form.idempotency_key = crypto.randomUUID(); kind.value = null; } });
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5"><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.equity') }}</h1><p class="mt-1 text-sm text-muted">{{ t('acct.equityHelp') }}</p></div>
        <AccountingNav />
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ t('acct.capital') }}</h2><button v-if="canPost" class="btn-primary !px-3" type="button" data-testid="add-capital" @click="kind = 'capital'"><NavIcon name="plus" :size="16" /> {{ t('acct.addCapital') }}</button></div>
                <ul v-if="contributions.length" class="divide-y divide-line"><li v-for="c in contributions" :key="c.id" class="flex justify-between gap-3 py-2.5 text-sm"><span><span class="block">{{ c.date }} · {{ c.account }}</span><span class="text-xs text-muted">{{ c.note }}</span></span><span class="font-semibold tabular text-ok">+{{ formatBdt(c.amount) }}</span></li></ul>
                <p v-else class="py-6 text-center text-sm text-muted">{{ t('acct.noCapital') }}</p>
            </section>
            <section class="card p-5">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ t('acct.drawing') }}</h2><button v-if="canPost" class="btn-secondary !px-3" type="button" data-testid="add-drawing" @click="kind = 'drawing'"><NavIcon name="plus" :size="16" /> {{ t('acct.addDrawing') }}</button></div>
                <ul v-if="drawings.length" class="divide-y divide-line"><li v-for="c in drawings" :key="c.id" class="flex justify-between gap-3 py-2.5 text-sm"><span><span class="block">{{ c.date }} · {{ c.account }}</span><span class="text-xs text-muted">{{ c.note }}</span></span><span class="font-semibold tabular text-danger">-{{ formatBdt(c.amount) }}</span></li></ul>
                <p v-else class="py-6 text-center text-sm text-muted">{{ t('acct.noCapital') }}</p>
            </section>
        </div>
        <Sheet :open="!!kind" :title="kind === 'capital' ? t('acct.addCapital') : t('acct.addDrawing')" @close="kind = null">
            <form class="space-y-4" novalidate @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3"><div><label class="label" for="qd">{{ t('acct.date') }}</label><input id="qd" v-model="form.date" type="date" class="field" :max="today" /><p v-if="err('date')" class="err" role="alert">{{ err('date') }}</p></div>
                    <div><label class="label" for="qa">{{ t('acct.amount') }}</label><input id="qa" v-model="form.amount" class="field !text-lg" inputmode="decimal" /><p v-if="err('amount')" class="err" role="alert">{{ err('amount') }}</p></div></div>
                <div><label class="label" for="qf">{{ kind === 'capital' ? 'Into account' : 'From account' }}</label><select id="qf" v-model="form.financial_account_id" class="field"><option v-for="f in financial" :key="f.id" :value="f.id">{{ f.name }}</option></select></div>
                <div><label class="label" for="qn">{{ t('acct.note') }}</label><input id="qn" v-model="form.note" class="field" maxlength="300" /></div>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('acct.save') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
