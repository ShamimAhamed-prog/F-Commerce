<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AccountingNav from '@/Components/AccountingNav.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Pagination from '@/Components/Pagination.vue';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
defineProps<{ entries: any; filters: { source?: string }; canPost: boolean }>();
const { t } = useI18n();
const open = ref<Record<number, boolean>>({});
const target = ref<any | null>(null);
const reason = ref('');
const errors = ref('');
function reverse() {
    router.post(`/app/accounting/journal/${target.value.id}/reverse`, { reason: reason.value }, {
        preserveScroll: true, onSuccess: () => { target.value = null; reason.value = ''; errors.value = ''; },
        onError: (e) => (errors.value = Object.values(e).join(' ')),
    });
}
const src = (s: string) => s.replace(/_/g, ' ');
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <div class="mb-5"><p class="eyebrow">{{ t('acct.title') }}</p><h1 class="page-title mt-1">{{ t('acct.journal') }}</h1></div>
        <AccountingNav />
        <ul class="space-y-2">
            <li v-for="e in entries.data" :key="e.id" class="card overflow-hidden" data-testid="journal-entry">
                <button type="button" class="flex w-full touch-target items-center justify-between gap-3 p-3.5 text-left" :aria-expanded="!!open[e.id]" @click="open[e.id] = !open[e.id]">
                    <span class="min-w-0"><span class="block truncate font-medium">{{ e.memo }}</span><span class="text-xs text-muted">{{ e.entry_number }} · {{ e.date }} · {{ src(e.source_type) }}</span></span>
                    <span class="flex shrink-0 items-center gap-2"><StatusBadge v-if="e.reversed" tone="muted">{{ t('acct.reversed') }}</StatusBadge><StatusBadge v-if="e.is_reversal" tone="warn">{{ t('acct.reversal') }}</StatusBadge></span>
                </button>
                <div v-if="open[e.id]" class="border-t border-line bg-surface-2/60 p-3.5">
                    <table class="w-full text-sm"><thead class="text-left text-xs text-muted"><tr><th class="pb-1">{{ t('acct.name') }}</th><th class="pb-1 text-right">{{ t('acct.debit') }}</th><th class="pb-1 text-right">{{ t('acct.credit') }}</th></tr></thead>
                        <tbody><tr v-for="(l, i) in e.lines" :key="i"><td class="py-1" :class="Number(l.credit) ? 'pl-5' : ''">{{ l.account }}</td><td class="py-1 text-right tabular">{{ Number(l.debit) ? formatBdt(l.debit) : '' }}</td><td class="py-1 text-right tabular">{{ Number(l.credit) ? formatBdt(l.credit) : '' }}</td></tr></tbody></table>
                    <button v-if="canPost && !e.reversed && !e.is_reversal" type="button" class="btn-secondary mt-3" @click="target = e">{{ t('acct.reverse') }}</button>
                </div>
            </li>
        </ul>
        <Pagination :meta="entries" />
        <Sheet :open="!!target" :title="t('acct.reverse')" @close="target = null">
            <form v-if="target" class="space-y-4" novalidate @submit.prevent="reverse">
                <p class="text-sm text-muted">{{ t('acct.confirmReverse') }}</p><p class="text-sm font-medium">{{ target.entry_number }} · {{ target.memo }}</p>
                <div><label class="label" for="rr">{{ t('acct.reason') }}</label><input id="rr" v-model="reason" class="field" maxlength="200" /><p v-if="errors" class="err" role="alert">{{ errors }}</p></div>
                <OfflineGuard type="submit" button-class="btn-primary w-full">{{ t('acct.reverse') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
