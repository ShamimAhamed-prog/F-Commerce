<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { stripBase } from '@/lib/base';
import { usePermissions } from '@/composables/usePermissions';

const { t } = useI18n();
const page = usePage();
const { can } = usePermissions();
const tabs = computed(() => [
    { k: 'overview', href: '/app/accounting', ok: true, exact: true },
    { k: 'expenses', href: '/app/accounting/expenses', ok: can('expenses.view') },
    { k: 'cod', href: '/app/accounting/cod', ok: true },
    { k: 'equity', href: '/app/accounting/equity', ok: true },
    { k: 'loans', href: '/app/accounting/loans', ok: true },
    { k: 'journal', href: '/app/accounting/journal', ok: true },
    { k: 'accounts', href: '/app/accounting/accounts', ok: true },
    { k: 'reports', href: can('profit.view') ? '/app/reports/profit-loss' : '/app/reports/trial-balance', ok: can('reports.view'), prefix: '/app/reports' },
].filter((x) => x.ok));
const on = (x: any) => { const p = stripBase(page.url.split('?')[0]); return x.exact ? p === x.href : x.prefix ? p.startsWith(x.prefix) : p.startsWith(x.href); };
</script>

<template>
    <nav class="-mx-4 mb-5 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Accounting sections">
        <ul class="inline-flex gap-1 rounded-2xl border border-line bg-card p-1">
            <li v-for="x in tabs" :key="x.k"><Link :href="x.href" class="block whitespace-nowrap rounded-xl px-3.5 py-2 text-sm font-medium transition" :class="on(x) ? 'bg-brand-soft text-brand' : 'text-muted hover:text-ink'" :aria-current="on(x) ? 'page' : undefined">{{ t(`acct.${x.k}`) }}</Link></li>
        </ul>
    </nav>
</template>
