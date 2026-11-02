<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageManager from '@/Components/ImageManager/ImageManager.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import { clearDraft, loadDraft, saveDraft } from '@/composables/useDraft';
import { useDirtyGuard } from '@/composables/useDirtyGuard';
import { usePermissions } from '@/composables/usePermissions';
import { useTenant } from '@/composables/useTenant';

const props = defineProps<{ product: any | null; showCost: boolean; canManageImages?: boolean; images?: any }>();
const { t } = useI18n();
const { can } = usePermissions();
const { tenant, user } = useTenant();
const editing = computed(() => !!props.product);

const blankVariant = () => ({ id: undefined as number | undefined, sku: '', barcode: '', selling_price: '', purchase_cost: '', opening_stock: '', options: [{ k: '', v: '' }] as { k: string; v: string }[] });
const p = props.product;
const form = useForm({
    name: p?.name ?? '', sku: p?.sku ?? '', type: p?.type ?? 'simple', description: p?.description ?? '',
    category: p?.category ?? '', brand: p?.brand ?? '', selling_price: p?.selling_price ?? '', purchase_cost: p?.purchase_cost ?? '',
    compare_at_price: p?.compare_at_price ?? '', tax_rate: p?.tax_rate ?? '', barcode: p?.barcode ?? '', weight: p?.weight ?? '',
    dimensions: p?.dimensions ?? '', status: p?.status ?? 'draft', reorder_threshold: p?.reorder_threshold ?? 0, opening_stock: '',
    variants: (p?.variants ?? []).map((v: any) => ({ ...blankVariant(), ...v, selling_price: v.selling_price ?? '', purchase_cost: v.purchase_cost ?? '', barcode: v.barcode ?? '',
        options: Object.entries(v.options ?? {}).map(([k, val]) => ({ k, v: String(val) })) })),
});

useDirtyGuard(`product-form:${p?.id ?? 'new'}`, computed(() => form.isDirty));

// Unsent draft (OFF-10): create form only, scoped to user+tenant, never auto-submitted.
const scope = computed(() => ({ userId: user.value?.id ?? 0, tenantId: tenant.value?.id ?? 0, form: 'product-create' }));
const restored = ref(false);
onMounted(() => {
    if (editing.value) return;
    const d = loadDraft(scope.value);
    if (d) { Object.assign(form, d.values); restored.value = true; }
});
function persistDraft() { if (!editing.value) saveDraft(scope.value, { ...form.data() }); }
function discardDraft() { clearDraft(scope.value); restored.value = false; form.reset(); }

function payload() {
    return {
        ...form.data(),
        variants: form.type === 'variable'
            ? form.variants.map((v: any) => ({
                id: v.id, sku: v.sku, barcode: v.barcode, selling_price: v.selling_price, purchase_cost: v.purchase_cost, opening_stock: v.opening_stock,
                options: Object.fromEntries(v.options.filter((o: any) => o.k && o.v).map((o: any) => [o.k, o.v])),
            }))
            : undefined,
    };
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => clearDraft(scope.value) };
    form.transform(() => payload() as any);
    editing.value ? form.put(`/app/products/${p.id}`, opts) : form.post('/app/products', opts);
}
function remove() {
    if (window.confirm(t('products.confirmDelete'))) router.delete(`/app/products/${p.id}`);
}
const err = (k: string) => (form.errors as Record<string, string>)[k];
const variantErr = (i: number) => err(`variants.${i}.sku`) || err(`variants.${i}.options`) || err(`variants.${i}.selling_price`);
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ editing ? p.name : t('products.new') }}</h1>
            <Link href="/app/products" class="btn-secondary">{{ t('common.back') }}</Link>
        </div>

        <p v-if="restored" class="flex items-center justify-between gap-2 rounded-lg bg-warn/15 px-3 py-2 text-sm" role="status">
            <span>Restored unsent draft</span><button type="button" class="underline" @click="discardDraft">{{ t('common.clear') }}</button>
        </p>

        <form class="space-y-6 pb-24" novalidate @submit.prevent="submit" @input="persistDraft">
            <section class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('products.details') }}</h2>
                <div>
                    <label class="label" for="name">{{ t('products.name') }}</label>
                    <input id="name" v-model="form.name" class="field" required maxlength="200" />
                    <p v-if="err('name')" class="err" role="alert">{{ err('name') }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="sku">{{ t('products.sku') }}</label>
                        <input id="sku" v-model="form.sku" class="field" required maxlength="100" autocapitalize="characters" />
                        <p v-if="err('sku')" class="err" role="alert">{{ err('sku') }}</p>
                    </div>
                    <div>
                        <label class="label" for="type">{{ t('products.type') }}</label>
                        <select id="type" v-model="form.type" class="field" :disabled="editing">
                            <option value="simple">{{ t('products.simple') }}</option>
                            <option value="variable">{{ t('products.variable') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="category">{{ t('products.category') }}</label>
                        <input id="category" v-model="form.category" class="field" maxlength="120" />
                    </div>
                    <div>
                        <label class="label" for="brand">{{ t('products.brand') }}</label>
                        <input id="brand" v-model="form.brand" class="field" maxlength="120" />
                    </div>
                </div>
                <div>
                    <label class="label" for="desc">{{ t('products.description') }}</label>
                    <textarea id="desc" v-model="form.description" rows="3" class="field" maxlength="10000"></textarea>
                </div>
                <div>
                    <label class="label" for="status">{{ t('common.status') }}</label>
                    <select id="status" v-model="form.status" class="field">
                        <option v-for="s in ['draft', 'active', 'archived']" :key="s" :value="s">{{ t(`products.${s}`) }}</option>
                    </select>
                    <p v-if="err('status')" class="err" role="alert">{{ err('status') }}</p>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('products.pricing') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="price">{{ t('products.price') }} (৳)</label>
                        <input id="price" v-model="form.selling_price" class="field" inputmode="decimal" required />
                        <p v-if="err('selling_price')" class="err" role="alert">{{ err('selling_price') }}</p>
                    </div>
                    <div v-if="showCost">
                        <label class="label" for="cost">{{ t('products.cost') }} (৳)</label>
                        <input id="cost" v-model="form.purchase_cost" class="field" inputmode="decimal" />
                        <p v-if="err('purchase_cost')" class="err" role="alert">{{ err('purchase_cost') }}</p>
                    </div>
                    <div>
                        <label class="label" for="cmp">{{ t('products.compareAt') }}</label>
                        <input id="cmp" v-model="form.compare_at_price" class="field" inputmode="decimal" />
                    </div>
                    <div>
                        <label class="label" for="tax">{{ t('products.tax') }}</label>
                        <input id="tax" v-model="form.tax_rate" class="field" inputmode="decimal" />
                    </div>
                    <div>
                        <label class="label" for="barcode">{{ t('products.barcode') }}</label>
                        <input id="barcode" v-model="form.barcode" class="field" inputmode="numeric" />
                    </div>
                    <div>
                        <label class="label" for="weight">{{ t('products.weight') }}</label>
                        <input id="weight" v-model="form.weight" class="field" inputmode="decimal" />
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('products.inventory') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="reorder">{{ t('products.reorder') }}</label>
                        <input id="reorder" v-model="form.reorder_threshold" class="field" inputmode="numeric" />
                    </div>
                    <div v-if="!editing && form.type === 'simple'">
                        <label class="label" for="open">{{ t('products.openingStock') }}</label>
                        <input id="open" v-model="form.opening_stock" class="field" inputmode="numeric" />
                        <p v-if="err('opening_stock')" class="err" role="alert">{{ err('opening_stock') }}</p>
                    </div>
                </div>
            </section>

            <section v-if="form.type === 'variable'" class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('products.variants') }}</h2>
                <p v-if="err('variants')" class="err" role="alert">{{ err('variants') }}</p>
                <div v-for="(v, i) in (form.variants as any[])" :key="i" class="space-y-3 rounded-lg border border-line p-3" data-testid="variant-row">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label" :for="`vsku${i}`">{{ t('products.variantSku') }}</label>
                            <input :id="`vsku${i}`" v-model="v.sku" class="field" maxlength="100" />
                        </div>
                        <div>
                            <label class="label" :for="`vprice${i}`">{{ t('products.price') }}</label>
                            <input :id="`vprice${i}`" v-model="v.selling_price" class="field" inputmode="decimal" :placeholder="String(form.selling_price)" />
                        </div>
                        <div v-if="showCost">
                            <label class="label" :for="`vcost${i}`">{{ t('products.cost') }}</label>
                            <input :id="`vcost${i}`" v-model="v.purchase_cost" class="field" inputmode="decimal" />
                        </div>
                        <div v-if="!v.id">
                            <label class="label" :for="`vopen${i}`">{{ t('products.openingStock') }}</label>
                            <input :id="`vopen${i}`" v-model="v.opening_stock" class="field" inputmode="numeric" />
                        </div>
                    </div>
                    <div v-for="(o, j) in v.options" :key="j" class="grid grid-cols-2 gap-3">
                        <input v-model="o.k" class="field" :aria-label="t('products.optionName')" :placeholder="t('products.optionName')" maxlength="100" />
                        <input v-model="o.v" class="field" :aria-label="t('products.optionValue')" :placeholder="t('products.optionValue')" maxlength="100" />
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <button v-if="v.options.length < 3" type="button" class="btn-secondary" @click="v.options.push({ k: '', v: '' })">+ {{ t('products.optionName') }}</button>
                        <button type="button" class="btn-secondary !text-danger" @click="form.variants.splice(i, 1)">{{ t('common.remove') }}</button>
                    </div>
                    <p v-if="variantErr(i)" class="err" role="alert">{{ variantErr(i) }}</p>
                </div>
                <button type="button" class="btn-secondary" @click="form.variants.push(blankVariant())">{{ t('products.addVariant') }}</button>
            </section>

            <!-- Sticky primary action bar (UI-9), above the mobile tab bar -->
            <div class="fixed inset-x-0 z-20 flex items-center justify-between gap-3 border-t border-line bg-card px-4 py-3 sm:static sm:border-0 sm:bg-transparent sm:p-0"
                 style="bottom: calc(var(--tabbar-h) + var(--safe-bottom))">
                <OfflineGuard v-if="editing && can('products.delete')" button-class="btn-secondary !text-danger" @click="remove">{{ t('products.deleteProduct') }}</OfflineGuard>
                <span v-else></span>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary">{{ t('products.saveProduct') }}</OfflineGuard>
            </div>
        </form>

        <div v-if="editing && images" class="rounded-xl border border-line bg-card p-4">
            <ImageManager :product-id="p.id" :initial="images" :can-manage="!!canManageImages"
                :variants="(p.variants ?? []).map((v: any) => ({ id: v.id, sku: v.sku, options: v.options }))" />
            <Link v-if="canManageImages" :href="`/app/products/missing-images/next?after=${p.id}`" class="btn-secondary mt-4">{{ t('products.nextMissing') }}</Link>
        </div>
        <p v-else-if="!editing" class="text-sm text-muted">{{ t('products.saveFirstForImages') }}</p>
    </div>
</template>
