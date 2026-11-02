<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import { useDirtyGuard } from '@/composables/useDirtyGuard';
import { clearDraft, loadDraft, saveDraft } from '@/composables/useDraft';
import { formatBdt } from '@/composables/useCurrency';
import { useConnection } from '@/composables/useConnection';
import { useTenant } from '@/composables/useTenant';
import AppLayout from '@/Layouts/AppLayout.vue';
import { estimate } from '@/lib/money';

defineOptions({ layout: AppLayout });
const { t } = useI18n();
const conn = useConnection();
const { tenant, user } = useTenant();

const step = ref(1);
const steps = ['step1', 'step2', 'step3', 'step4'];
// One idempotency key per submit INTENT (OFF-8): re-submitting after an uncertain result cannot duplicate the order.
const form = useForm({
    idempotency_key: crypto.randomUUID(), customer_name: '', phone: '', address: '', area: '', notes: '',
    order_discount: '', shipping_charge: '', other_charges: '', tax_amount: '', advance: '', status: 'pending_confirmation',
    items: [] as { product_id: number; variant_id: number | null; name: string; sku: string; qty: number; unit_price: string; discount: string }[],
});
useDirtyGuard('order-create', computed(() => form.isDirty));

const est = computed(() => estimate(form.items, form));
const err = (k: string) => (form.errors as Record<string, string>)[k];

// ---- unsent draft (OFF-10): non-sensitive fields only, user+tenant scoped, never auto-submitted
const scope = computed(() => ({ userId: user.value?.id ?? 0, tenantId: tenant.value?.id ?? 0, form: 'order-create' }));
const restored = ref(false);
onMounted(() => {
    const d = loadDraft(scope.value);
    if (d) { const { idempotency_key, ...rest } = d.values as any; Object.assign(form, rest); restored.value = true; }
});
watch(() => form.data(), (v) => { const { idempotency_key, ...rest } = v; if (form.isDirty) saveDraft(scope.value, rest); }, { deep: true });

// ---- item picker
const q = ref('');
const results = ref<any[]>([]);
let timer: ReturnType<typeof setTimeout>;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (!q.value.trim()) return (results.value = []);
        try { results.value = (await axios.get('/app/orders/product-search', { params: { q: q.value } })).data; } catch { void conn.reportFailure(); }
    }, 250);
});
function add(r: any) {
    const ex = form.items.find((i) => i.product_id === r.product_id && i.variant_id === r.variant_id);
    ex ? ex.qty++ : form.items.push({ product_id: r.product_id, variant_id: r.variant_id, name: r.name, sku: r.sku, qty: 1, unit_price: r.price, discount: '' });
    q.value = ''; results.value = [];
}

// ---- uncertain result (OFF-8)
const uncertain = ref(false);
let off: (() => void) | null = null;
function submit(status: 'draft' | 'pending_confirmation') {
    uncertain.value = false;
    form.status = status;
    off = router.on('networkError', () => { uncertain.value = true; void conn.reportFailure(); });
    form.post('/app/orders', {
        preserveScroll: true,
        onSuccess: () => clearDraft(scope.value),
        onFinish: () => { off?.(); off = null; },
    });
}
onBeforeUnmount(() => off?.());

const canNext = computed(() => (step.value === 1 ? !!(form.customer_name && form.phone) : step.value === 2 ? form.items.length > 0 : step.value === 3 ? !!form.address : true));
const advance = () => (step.value < 4 ? step.value++ : undefined);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-4 pb-40">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ t('orders.new') }}</h1>
            <Link href="/app/orders" class="btn-secondary">{{ t('common.cancel') }}</Link>
        </div>

        <ol class="flex gap-1 text-xs" aria-label="Progress">
            <li v-for="(s, i) in steps" :key="s" class="flex-1 rounded-full px-2 py-1 text-center" :class="i + 1 === step ? 'bg-brand text-brand-ink' : i + 1 < step ? 'bg-brand/20' : 'bg-line text-muted'" :aria-current="i + 1 === step ? 'step' : undefined">{{ i + 1 }}. {{ t(`orders.${s}`) }}</li>
        </ol>

        <p v-if="restored" class="rounded-lg bg-warn/15 px-3 py-2 text-sm" role="status">{{ t('orders.restoredDraft') }}</p>

        <div v-if="uncertain" class="rounded-xl border border-warn bg-warn/10 p-4 text-sm" role="alert" data-testid="uncertain">
            <p class="font-semibold">{{ t('orders.uncertainTitle') }}</p>
            <p class="mt-1">{{ t('orders.uncertainBody') }}</p>
            <div class="mt-3 flex gap-2">
                <Link href="/app/orders" class="btn-secondary">{{ t('orders.checkOrders') }}</Link>
                <OfflineGuard button-class="btn-primary" @click="submit('pending_confirmation')">{{ t('orders.submitAgain') }}</OfflineGuard>
            </div>
        </div>

        <!-- 1 customer -->
        <section v-show="step === 1" class="space-y-4 rounded-xl border border-line bg-card p-4">
            <div><label class="label" for="cn">{{ t('orders.customer') }}</label><input id="cn" v-model="form.customer_name" class="field" autocomplete="name" /><p v-if="err('customer_name')" class="err">{{ err('customer_name') }}</p></div>
            <div><label class="label" for="ph">{{ t('orders.phone') }}</label><input id="ph" v-model="form.phone" class="field" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" /><p v-if="err('phone')" class="err" role="alert">{{ err('phone') }}</p></div>
        </section>

        <!-- 2 items -->
        <section v-show="step === 2" class="space-y-3 rounded-xl border border-line bg-card p-4">
            <input v-model="q" type="search" class="field" :placeholder="t('orders.searchItems')" :aria-label="t('orders.searchItems')" data-testid="item-search" />
            <ul v-if="results.length" class="divide-y divide-line rounded-lg border border-line">
                <li v-for="r in results" :key="`${r.product_id}-${r.variant_id}`">
                    <button type="button" class="flex w-full touch-target items-center justify-between gap-3 p-3 text-left" data-testid="item-result" @click="add(r)">
                        <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ r.name }}</span><span class="text-xs text-muted">{{ r.sku }} · {{ t('orders.available', { n: r.available }) }}</span></span>
                        <span class="shrink-0 text-sm">{{ formatBdt(r.price) }}</span>
                    </button>
                </li>
            </ul>
            <p v-if="!form.items.length" class="text-sm text-muted">{{ t('orders.itemsEmpty') }}</p>
            <p v-if="err('items')" class="err" role="alert">{{ err('items') }}</p>
            <ul class="space-y-3">
                <li v-for="(it, i) in form.items" :key="`${it.product_id}-${it.variant_id}`" class="space-y-2 rounded-lg border border-line p-3" data-testid="order-line">
                    <div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="truncate text-sm font-medium">{{ it.name }}</p><p class="text-xs text-muted">{{ it.sku }}</p></div>
                        <button type="button" class="btn-secondary !px-3 !text-danger" :aria-label="t('common.remove')" @click="form.items.splice(i, 1)">✕</button></div>
                    <div class="grid grid-cols-3 gap-2">
                        <div><label class="label" :for="`q${i}`">{{ t('orders.qty') }}</label><input :id="`q${i}`" v-model.number="it.qty" class="field" inputmode="numeric" min="1" /></div>
                        <div><label class="label" :for="`p${i}`">{{ t('orders.unitPrice') }}</label><input :id="`p${i}`" v-model="it.unit_price" class="field" inputmode="decimal" /></div>
                        <div><label class="label" :for="`d${i}`">{{ t('orders.discount') }}</label><input :id="`d${i}`" v-model="it.discount" class="field" inputmode="decimal" /></div>
                    </div>
                </li>
            </ul>
        </section>

        <!-- 3 delivery & charges -->
        <section v-show="step === 3" class="space-y-4 rounded-xl border border-line bg-card p-4">
            <div><label class="label" for="ad">{{ t('orders.address') }}</label><textarea id="ad" v-model="form.address" rows="3" class="field" autocomplete="street-address"></textarea><p v-if="err('address')" class="err" role="alert">{{ err('address') }}</p></div>
            <div><label class="label" for="ar">{{ t('orders.area') }}</label><input id="ar" v-model="form.area" class="field" /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label" for="sh">{{ t('orders.shipping') }}</label><input id="sh" v-model="form.shipping_charge" class="field" inputmode="decimal" /></div>
                <div><label class="label" for="oc">{{ t('orders.other') }}</label><input id="oc" v-model="form.other_charges" class="field" inputmode="decimal" /></div>
                <div><label class="label" for="od">{{ t('orders.orderDiscount') }}</label><input id="od" v-model="form.order_discount" class="field" inputmode="decimal" /></div>
                <div><label class="label" for="tx">{{ t('orders.tax') }}</label><input id="tx" v-model="form.tax_amount" class="field" inputmode="decimal" /></div>
                <div class="col-span-2"><label class="label" for="av">{{ t('orders.advance') }}</label><input id="av" v-model="form.advance" class="field" inputmode="decimal" /><p v-if="est.advanceTooHigh" class="err" role="alert">Advance exceeds the total.</p></div>
            </div>
            <div><label class="label" for="nt">{{ t('orders.notes') }}</label><textarea id="nt" v-model="form.notes" rows="2" class="field"></textarea></div>
        </section>

        <!-- 4 review -->
        <section v-show="step === 4" class="space-y-3 rounded-xl border border-line bg-card p-4 text-sm">
            <p><strong>{{ form.customer_name }}</strong> · {{ form.phone }}</p><p class="text-muted">{{ form.address }}<span v-if="form.area">, {{ form.area }}</span></p>
            <ul class="divide-y divide-line"><li v-for="it in form.items" :key="`${it.product_id}-${it.variant_id}`" class="flex justify-between py-1"><span>{{ it.qty }} × {{ it.name }}</span><span>{{ formatBdt(it.unit_price) }}</span></li></ul>
            <p v-if="Object.keys(form.errors).length" class="err" role="alert">{{ Object.values(form.errors).join(' ') }}</p>
        </section>

        <!-- Sticky total/COD summary + step actions (UI-9, §5.5) -->
        <div class="fixed inset-x-0 z-20 space-y-2 border-t border-line bg-card px-4 py-3 sm:static sm:rounded-xl sm:border" style="bottom: calc(var(--tabbar-h) + var(--safe-bottom))">
            <div class="flex items-end justify-between text-sm" data-testid="summary">
                <div><p class="text-xs text-muted">{{ t('orders.total') }}</p><p class="text-lg font-bold" data-testid="est-total">{{ formatBdt(est.total) }}</p></div>
                <div class="text-right"><p class="text-xs text-muted">{{ t('orders.cod') }}</p><p class="text-lg font-bold" data-testid="est-cod">{{ formatBdt(est.cod) }}</p></div>
            </div>
            <p class="text-[11px] text-muted">{{ t('orders.estimate') }}</p>
            <div class="flex gap-2">
                <button v-if="step > 1" type="button" class="btn-secondary" @click="step--">{{ t('common.back') }}</button>
                <button v-if="step < 4" type="button" class="btn-primary flex-1" :disabled="!canNext" data-testid="next" @click="advance">{{ t('common.next') }}</button>
                <template v-else>
                    <OfflineGuard :pending="form.processing" button-class="btn-secondary" @click="submit('draft')">{{ t('orders.saveDraft') }}</OfflineGuard>
                    <OfflineGuard :pending="form.processing" button-class="btn-primary flex-1" @click="submit('pending_confirmation')">{{ t('orders.place') }}</OfflineGuard>
                </template>
            </div>
        </div>
    </div>
</template>
