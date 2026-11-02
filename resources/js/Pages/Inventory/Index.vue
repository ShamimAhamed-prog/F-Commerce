<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import Pagination from '@/Components/Pagination.vue';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

defineOptions({ layout: AppLayout });
const props = defineProps<{ units: any; filters: { search?: string; low_stock?: string }; valuation: string | null; canAdjust: boolean }>();
const { t } = useI18n();

const search = ref(props.filters.search ?? '');
const low = ref(!!props.filters.low_stock);
let timer: ReturnType<typeof setTimeout>;
const apply = () => router.get('/app/inventory', { search: search.value || undefined, low_stock: low.value ? 1 : undefined }, { preserveState: true, replace: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
watch(low, apply);

const unit = ref<any | null>(null);
const history = ref<any[]>([]);
const form = useForm({ product_id: 0, variant_id: null as number | null, mode: 'delta', quantity: '' as string | number, reason: '', unit_cost: '' });
const label = (u: any) => (u.options ? `${u.name} — ${Object.values(u.options).join(' / ')}` : u.name);

async function open(u: any) {
    unit.value = u;
    form.reset();
    form.product_id = u.product_id;
    form.variant_id = u.variant_id;
    history.value = [];
    try {
        history.value = (await axios.get('/app/inventory/movements', { params: { product_id: u.product_id, variant_id: u.variant_id ?? undefined } })).data;
    } catch { /* history is optional */ }
}
const preview = computed(() => {
    const q = Number(form.quantity);
    if (!unit.value || form.quantity === '' || Number.isNaN(q)) return null;
    return form.mode === 'count' ? q : unit.value.on_hand + q;
});
const submit = () => form.post('/app/inventory/adjust', { preserveScroll: true, onSuccess: () => (unit.value = null) });
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ t('inventory.title') }}</h1>
            <p v-if="valuation !== null" class="text-sm"><span class="text-muted">{{ t('inventory.valuation') }}:</span> <strong>{{ formatBdt(valuation) }}</strong></p>
        </div>
        <div class="sticky top-16 z-10 -mx-4 space-y-2 bg-surface px-4 py-2 sm:mx-0 sm:px-0">
            <input v-model="search" type="search" class="field" :placeholder="t('products.searchPlaceholder')" :aria-label="t('common.search')" />
            <label class="flex touch-target items-center gap-2 text-sm"><input v-model="low" type="checkbox" class="h-5 w-5" /> {{ t('inventory.lowOnly') }}</label>
        </div>

        <p v-if="!units.data.length" class="rounded-xl border border-line bg-card p-6 text-center text-sm text-muted">{{ t('inventory.noUnits') }}</p>

        <ul class="space-y-2">
            <li v-for="u in units.data" :key="`${u.product_id}-${u.variant_id}`" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ label(u) }}</p>
                    <p class="truncate text-xs text-muted">{{ u.sku }}<span v-if="u.avg_cost !== null"> · {{ t('inventory.avgCost') }} {{ formatBdt(u.avg_cost) }}</span></p>
                    <p class="mt-1 text-sm">
                        {{ t('inventory.onHand') }} <strong>{{ u.on_hand }}</strong>
                        <span v-if="u.reserved" class="text-muted"> · {{ t('inventory.reserved') }} {{ u.reserved }}</span>
                        <StatusBadge v-if="u.low" tone="warn" class="ml-2">{{ t('inventory.low') }}</StatusBadge>
                    </p>
                </div>
                <button v-if="canAdjust" type="button" class="btn-secondary shrink-0" @click="open(u)">{{ t('inventory.adjust') }}</button>
            </li>
        </ul>
        <Pagination :meta="units" />

        <Sheet :open="!!unit" :title="t('inventory.adjust')" @close="unit = null">
            <form v-if="unit" class="space-y-4" novalidate @submit.prevent="submit">
                <p class="text-sm"><strong>{{ label(unit) }}</strong> · {{ t('inventory.onHand') }} {{ unit.on_hand }}</p>
                <div role="radiogroup" class="grid grid-cols-2 gap-2">
                    <label class="btn-secondary cursor-pointer" :class="{ '!border-brand': form.mode === 'delta' }"><input v-model="form.mode" type="radio" value="delta" class="sr-only" /> {{ t('inventory.adjustDelta') }}</label>
                    <label class="btn-secondary cursor-pointer" :class="{ '!border-brand': form.mode === 'count' }"><input v-model="form.mode" type="radio" value="count" class="sr-only" /> {{ t('inventory.adjustCount') }}</label>
                </div>
                <div>
                    <label class="label" for="qty">{{ t('inventory.quantity') }}</label>
                    <!-- Large numeric input for stock counts (§5.4). Negative numbers allowed for removals. -->
                    <input id="qty" v-model="form.quantity" class="field !text-2xl" inputmode="numeric" pattern="-?[0-9]*" autofocus />
                    <p v-if="preview !== null" class="mt-1 text-sm text-muted">→ {{ preview }}</p>
                    <p v-if="err('quantity')" class="err" role="alert">{{ err('quantity') }}</p>
                </div>
                <div>
                    <label class="label" for="reason">{{ t('inventory.reason') }}</label>
                    <input id="reason" v-model="form.reason" class="field" maxlength="200" />
                    <p class="text-xs text-muted">{{ t('inventory.reasonHint') }}</p>
                    <p v-if="err('reason')" class="err" role="alert">{{ err('reason') }}</p>
                </div>
                <div v-if="unit.avg_cost !== null && form.mode === 'delta' && Number(form.quantity) > 0">
                    <label class="label" for="ucost">{{ t('inventory.unitCost') }}</label>
                    <input id="ucost" v-model="form.unit_cost" class="field" inputmode="decimal" />
                </div>
                <ul v-if="history.length" class="max-h-32 overflow-y-auto text-xs text-muted">
                    <li v-for="m in history.slice(0, 8)" :key="m.id">{{ m.type }} {{ m.qty > 0 ? '+' : '' }}{{ m.qty }} <span v-if="m.reason">· {{ m.reason }}</span></li>
                </ul>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('common.save') }}</OfflineGuard>
            </form>
        </Sheet>
    </div>
</template>
