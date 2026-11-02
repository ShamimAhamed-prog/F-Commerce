<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AreaChart from '@/Components/charts/AreaChart.vue';
import Donut from '@/Components/charts/Donut.vue';
import { formatBdt } from '@/composables/useCurrency';
import { usePermissions } from '@/composables/usePermissions';
import { useTenant } from '@/composables/useTenant';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{
    range: string; from: string; to: string; days: number;
    kpis: { gross_sales: string; prev_gross_sales: string; orders: number; prev_orders: number; avg_order_value: string };
    trend: { date: string; sales: string; orders: number }[];
    pipeline: Record<string, number>;
    attention: { awaiting_confirmation: number; ready_to_ship: number; in_transit: number; low_stock: number | null; low_stock_items: { name: string; on_hand: number; threshold: number }[]; missing_images: number | null };
    top_sellers: { name: string; qty: number; revenue: string }[];
    recent_orders: any[];
    finance: { cash: string; cod_receivable: string } | null;
    profit: { revenue: string; cogs: string; gross: string; expenses: string; ad_spend: string; other_opex: string; net: string } | null;
}>();
const { t } = useI18n();
const { user, tenant } = useTenant();
const { can } = usePermissions();

const hour = new Date().getHours();
const greet = computed(() => t(`dash.greeting.${hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'}`));
const first = computed(() => (user.value?.name ?? '').split(' ')[0]);
const ranges = ['7d', '30d', '90d', 'mtd'];
const setRange = (r: string) => router.get('/app/dashboard', { range: r }, { preserveScroll: true, preserveState: true, only: ['range', 'from', 'to', 'days', 'kpis', 'trend', 'pipeline', 'top_sellers', 'profit'], replace: true });

const pct = (cur: number, prev: number) => (prev > 0 ? Math.round(((cur - prev) / prev) * 100) : null);
const salesDelta = computed(() => pct(Number(props.kpis.gross_sales), Number(props.kpis.prev_gross_sales)));
const ordersDelta = computed(() => pct(props.kpis.orders, props.kpis.prev_orders));
const fmtDay = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const points = computed(() => props.trend.map((p) => ({ label: fmtDay(p.date), value: Number(p.sales) })));
const spark = computed(() => props.trend.map((p) => Number(p.sales)));
const orderSpark = computed(() => props.trend.map((p) => p.orders));

const statusColors: Record<string, string> = {
    pending_confirmation: 'var(--chart-3)', confirmed: 'var(--chart-1)', processing: 'var(--chart-5)', ready_to_ship: 'var(--chart-5)', shipped: 'var(--chart-1)',
    delivered: 'var(--chart-2)', cancelled: 'var(--chart-4)', delivery_failed: 'var(--chart-4)', return_requested: 'var(--chart-3)', returned: 'var(--chart-4)',
};
const segments = computed(() => Object.entries(props.pipeline).map(([k, v]) => ({ label: t(`orders.status_${k}`), value: v as number, color: statusColors[k] ?? 'var(--chart-5)' })).sort((a, b) => b.value - a.value));
const totalOrders = computed(() => segments.value.reduce((a, s) => a + s.value, 0));

const profitBars = computed(() => {
  const p = props.profit; if (!p) return [];
  const rev = Number(p.revenue) || 1;
  return [
    { label: t('dash.revenue'), v: p.revenue, w: 100, c: 'var(--chart-1)' },
    { label: t('dash.cogs'), v: p.cogs, w: (Number(p.cogs) / rev) * 100, c: 'var(--chart-3)' },
    { label: t('dash.adSpend'), v: p.ad_spend, w: (Number(p.ad_spend) / rev) * 100, c: 'var(--chart-4)' },
    { label: t('dash.otherExpenses'), v: p.other_opex, w: (Number(p.other_opex) / rev) * 100, c: 'var(--chart-5)' },
  ];
});
const tone = (s: string) => (s === 'delivered' ? 'ok' : ['cancelled', 'delivery_failed', 'returned'].includes(s) ? 'danger' : s === 'draft' ? 'muted' : 'warn');
const kpiCards = computed(() => {
  const k = props.kpis;
  return [
    { key: 'sales', label: t('dash.grossSales'), value: formatBdt(k.gross_sales), icon: 'trend', delta: salesDelta.value, hint: t('dash.vsPrev'), spark: spark.value, tone: 'brand' as const },
    { key: 'orders', label: t('dash.orders'), value: String(k.orders), icon: 'receipt', delta: ordersDelta.value, hint: t('dash.ordersHint', { n: props.attention.awaiting_confirmation }), spark: orderSpark.value, tone: 'info' as const, href: '/app/orders' },
    { key: 'aov', label: t('dash.aov'), value: formatBdt(k.avg_order_value), icon: 'cart', hint: t('dash.grossSalesHint'), tone: 'ok' as const },
    props.profit && { key: 'profit', label: t('dash.netProfit'), value: formatBdt(props.profit.net), icon: 'coins', hint: t('dash.profitHint'), tone: (Number(props.profit.net) >= 0 ? 'ok' : 'danger') as 'ok' | 'danger', href: '/app/reports/profit-loss' },
    props.finance && { key: 'cash', label: t('dash.cash'), value: formatBdt(props.finance.cash), icon: 'wallet', hint: t('dash.cashHint'), tone: 'warn' as const, href: '/app/accounting' },
    props.finance && { key: 'cod', label: t('dash.codReceivable'), value: formatBdt(props.finance.cod_receivable), icon: 'truck', hint: t('dash.codHint'), tone: 'info' as const, href: '/app/accounting/cod' },
  ].filter(Boolean) as any[];
});
const attentionItems = computed(() => [
  { key: 'awaiting', n: props.attention.awaiting_confirmation, href: '/app/orders?status=pending_confirmation', icon: 'clock', show: can('orders.view') },
  { key: 'ready', n: props.attention.ready_to_ship, href: '/app/orders', icon: 'box', show: can('orders.view') },
  { key: 'transit', n: props.attention.in_transit, href: '/app/orders?status=shipped', icon: 'truck', show: can('orders.view') },
  { key: 'lowStock', n: props.attention.low_stock, href: '/app/inventory?low_stock=1', icon: 'alert', show: props.attention.low_stock !== null },
  { key: 'missingImages', n: props.attention.missing_images, href: '/app/products/missing-images', icon: 'image', show: props.attention.missing_images !== null },
].filter((i) => i.show));
const allClear = computed(() => attentionItems.value.every((i) => !i.n));
</script>

<template>
    <div class="mx-auto max-w-[88rem] space-y-6">
        <!-- Header -->
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">{{ tenant?.name }}</p>
                <h1 class="page-title mt-1">{{ greet }}, {{ first }} 👋</h1>
                <p class="mt-1 text-sm text-muted">{{ t('dash.subtitle', { name: tenant?.name }) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div role="group" aria-label="Date range" class="inline-flex rounded-xl border border-line bg-card p-0.5">
                    <button v-for="r in ranges" :key="r" type="button" class="touch-target rounded-[0.65rem] px-3 text-sm font-medium transition" :aria-pressed="range === r" :data-range="r"
                        :class="range === r ? 'bg-brand-soft text-brand' : 'text-muted hover:text-ink'" @click="setRange(r)">{{ t(`dash.ranges.${r}`) }}</button>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Link v-if="can('orders.create')" href="/app/orders/create" class="btn-primary"><NavIcon name="plus" :size="16" /> {{ t('dash.newOrder') }}</Link>
            <Link v-if="can('products.create')" href="/app/products/create" class="btn-secondary"><NavIcon name="box" :size="16" /> {{ t('dash.addProduct') }}</Link>
            <Link v-if="can('expenses.manage')" href="/app/accounting/expenses" class="btn-secondary"><NavIcon name="wallet" :size="16" /> {{ t('dash.recordExpense') }}</Link>
        </div>

        <!-- KPI cards: only those the user may see (profit and finance are permissioned) -->
        <div class="grid grid-cols-2 gap-3 sm:gap-4" :class="kpiCards.length > 4 ? 'xl:grid-cols-3' : 'xl:grid-cols-4'">
            <StatCard v-for="c in kpiCards" :key="c.key" v-bind="c" />
        </div>

        <!-- Trend + pipeline -->
        <div class="grid gap-4 xl:grid-cols-3">
            <section class="card min-w-0 p-5 xl:col-span-2">
                <div class="mb-4 flex items-center justify-between"><h2 class="font-semibold">{{ t('dash.salesTrend') }}</h2><span class="text-xs text-muted">{{ fmtDay(from) }} – {{ fmtDay(to) }}</span></div>
                <AreaChart v-if="kpis.orders" :points="points" :format="(n) => formatBdt(n.toFixed(2))" :aria-label="t('dash.salesTrend')" />
                <div v-else class="grid h-60 place-items-center text-center text-sm text-muted"><div><NavIcon name="chart" :size="28" class="mx-auto mb-2 opacity-60" />{{ t('dash.noOrders') }}</div></div>
            </section>
            <section class="card min-w-0 p-5">
                <h2 class="mb-4 font-semibold">{{ t('dash.pipeline') }}</h2>
                <div v-if="totalOrders" class="flex flex-col items-center gap-4 sm:flex-row xl:flex-col">
                    <Donut :segments="segments" :center="String(totalOrders)" :sub="t('dash.orders')" />
                    <ul class="w-full space-y-1.5 text-sm">
                        <li v-for="s in segments" :key="s.label" class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: s.color }"></span>{{ s.label }}</span><span class="font-semibold tabular">{{ s.value }}</span></li>
                    </ul>
                </div>
                <p v-else class="grid h-52 place-items-center text-sm text-muted">{{ t('dash.noOrders') }}</p>
            </section>
        </div>

        <!-- Profit + attention -->
        <div class="grid gap-4 xl:grid-cols-3">
            <section v-if="profit" class="card min-w-0 p-5 xl:col-span-2">
                <div class="mb-4 flex items-center justify-between"><h2 class="font-semibold">{{ t('dash.profitBreakdown') }}</h2><Link href="/app/reports/profit-loss" class="link-action">P&amp;L →</Link></div>
                <div v-if="Number(profit.revenue) || Number(profit.expenses)" class="space-y-3">
                    <div v-for="b in profitBars" :key="b.label">
                        <div class="mb-1 flex justify-between text-sm"><span class="text-muted">{{ b.label }}</span><span class="font-semibold tabular">{{ formatBdt(b.v) }}</span></div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full transition-all duration-500" :style="{ width: `${Math.min(100, Math.max(1, b.w))}%`, background: b.c }"></div></div>
                    </div>
                    <div class="mt-4 flex items-center justify-between rounded-xl bg-surface-2 px-4 py-3"><span class="font-medium">{{ t('dash.netProfit') }}</span><span class="text-lg font-bold tabular" :class="Number(profit.net) >= 0 ? 'text-ok' : 'text-danger'">{{ formatBdt(profit.net) }}</span></div>
                </div>
                <p v-else class="py-8 text-center text-sm text-muted">{{ t('dash.noProfitData') }}</p>
            </section>
            <section class="card p-5" :class="profit ? '' : 'xl:col-span-2'">
                <h2 class="mb-3 font-semibold">{{ t('dash.needsAttention') }}</h2>
                <p v-if="allClear" class="flex items-center gap-2 rounded-xl bg-ok/10 px-3 py-3 text-sm"><NavIcon name="check" :size="18" class="text-ok" /> {{ t('dash.allGood') }}</p>
                <ul class="space-y-1.5">
                    <li v-for="i in attentionItems" :key="i.key">
                        <Link :href="i.href" class="flex touch-target items-center justify-between gap-3 rounded-xl px-3 py-2 transition hover:bg-surface-2" :data-testid="`attn-${i.key}`">
                            <span class="flex items-center gap-3 text-sm"><span class="grid h-8 w-8 place-items-center rounded-lg" :class="i.n ? 'bg-warn/12 text-warn' : 'bg-surface-2 text-muted'"><NavIcon :name="i.icon" :size="16" /></span>{{ t(`dash.${i.key}`) }}</span>
                            <span class="rounded-full px-2.5 py-0.5 text-sm font-bold tabular" :class="i.n ? 'bg-warn/12 text-warn' : 'text-muted'">{{ i.n }}</span>
                        </Link>
                    </li>
                </ul>
                <ul v-if="attention.low_stock_items.length" class="mt-3 space-y-1 border-t border-line pt-3 text-xs text-muted">
                    <li v-for="l in attention.low_stock_items" :key="l.name" class="flex justify-between"><span class="truncate">{{ l.name }}</span><span class="tabular">{{ l.on_hand }} / {{ l.threshold }}</span></li>
                </ul>
            </section>
        </div>

        <!-- Top sellers + recent orders -->
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="card min-w-0 p-5">
                <h2 class="mb-3 font-semibold">{{ t('dash.topSellers') }}</h2>
                <ul v-if="top_sellers.length" class="space-y-3">
                    <li v-for="(s, i) in top_sellers" :key="s.name">
                        <div class="mb-1 flex items-center justify-between gap-3 text-sm"><span class="flex min-w-0 items-center gap-2"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand">{{ i + 1 }}</span><span class="truncate">{{ s.name }}</span></span><span class="shrink-0 text-muted">{{ t('dash.units', { n: s.qty }) }} · <span class="font-semibold text-ink">{{ formatBdt(s.revenue) }}</span></span></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full" style="background-image: var(--grad-brand)" :style="{ width: `${(s.qty / top_sellers[0].qty) * 100}%` }"></div></div>
                    </li>
                </ul>
                <p v-else class="py-8 text-center text-sm text-muted">{{ t('dash.noSellers') }}</p>
            </section>
            <section class="card min-w-0 p-5">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ t('dash.recentOrders') }}</h2><Link v-if="can('orders.view')" href="/app/orders" class="link-action">{{ t('dash.viewAll') }}</Link></div>
                <ul v-if="recent_orders.length" class="divide-y divide-line">
                    <li v-for="o in recent_orders" :key="o.id"><Link :href="`/app/orders/${o.id}`" class="flex items-center justify-between gap-3 py-2.5">
                        <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ o.order_number }} · {{ o.customer_name }}</span><span class="text-xs text-muted">{{ formatBdt(o.total) }}</span></span>
                        <StatusBadge :tone="tone(o.status)">{{ t(`orders.status_${o.status}`) }}</StatusBadge></Link></li>
                </ul>
                <p v-else class="py-8 text-center text-sm text-muted">{{ t('orders.noOrders') }}</p>
            </section>
        </div>
    </div>
</template>
