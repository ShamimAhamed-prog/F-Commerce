<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatBdt } from '@/composables/useCurrency';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{
    products: { data: any[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { search?: string; status?: string; category?: string };
    categories: { id: number; name: string }[];
}>();
const { t } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const category = ref(props.filters.category ?? '');
let timer: ReturnType<typeof setTimeout>;
function apply() {
    router.get('/app/products', { search: search.value || undefined, status: status.value || undefined, category: category.value || undefined }, { preserveState: true, replace: true });
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
watch([status, category], apply);

const tone = (s: string) => (s === 'active' ? 'ok' : s === 'archived' ? 'muted' : 'warn');
const hasFilters = () => !!(search.value || status.value || category.value);
function clear() { search.value = ''; status.value = ''; category.value = ''; }
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold">{{ t('products.title') }}</h1>
            <div class="flex flex-wrap gap-2">
                <Link v-if="can('products.images.view')" href="/app/products/missing-images" class="btn-secondary">{{ t('products.missingImages') }}</Link>
                <Link v-if="can('products.import')" href="/app/imports" class="btn-secondary">{{ t('products.import') }}</Link>
                <Link v-if="can('products.create')" href="/app/products/create" class="btn-primary">{{ t('products.new') }}</Link>
            </div>
        </div>

        <!-- Sticky search + filters (UI-5) -->
        <div class="sticky top-16 z-10 -mx-4 space-y-2 bg-surface px-4 py-2 sm:mx-0 sm:px-0">
            <input v-model="search" type="search" class="field" :placeholder="t('products.searchPlaceholder')" :aria-label="t('common.search')" />
            <div class="flex flex-wrap gap-2">
                <select v-model="status" class="field !w-auto" :aria-label="t('common.status')">
                    <option value="">{{ t('common.status') }}: {{ t('common.all') }}</option>
                    <option v-for="s in ['draft', 'active', 'archived']" :key="s" :value="s">{{ t(`products.${s}`) }}</option>
                </select>
                <select v-model="category" class="field !w-auto" :aria-label="t('products.category')">
                    <option value="">{{ t('products.category') }}: {{ t('common.all') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
                <button v-if="hasFilters()" type="button" class="btn-secondary" @click="clear">{{ t('common.clear') }}</button>
            </div>
        </div>

        <p v-if="!products.data.length" class="rounded-xl border border-line bg-card p-6 text-center text-sm text-muted">
            {{ hasFilters() ? t('common.noResults') : t('products.noProducts') }}
        </p>

        <!-- Mobile: card list showing the 3-5 key fields -->
        <ul class="space-y-2 md:hidden">
            <li v-for="p in products.data" :key="p.id">
                <Link :href="`/app/products/${p.id}/edit`" class="flex items-center gap-3 rounded-xl border border-line bg-card p-3">
                    <img v-if="p.thumb" :src="p.thumb" alt="" class="h-16 w-16 shrink-0 rounded-lg object-cover" width="64" height="64" loading="lazy" />
                    <div v-else class="h-16 w-16 shrink-0 rounded-lg bg-surface" aria-hidden="true"></div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ p.name }}</p>
                        <p class="truncate text-xs text-muted">{{ p.sku }}</p>
                        <p class="mt-1 text-sm">{{ formatBdt(p.selling_price) }} · {{ t('products.stock') }}: {{ p.on_hand }}</p>
                    </div>
                    <StatusBadge :tone="tone(p.status)">{{ t(`products.${p.status}`) }}</StatusBadge>
                </Link>
            </li>
        </ul>

        <!-- Desktop/tablet: table -->
        <div v-if="products.data.length" class="hidden overflow-x-auto rounded-xl border border-line bg-card md:block">
            <table class="w-full text-sm">
                <thead class="border-b border-line text-left text-xs text-muted">
                    <tr>
                        <th class="p-3" scope="col"></th>
                        <th class="p-3" scope="col">{{ t('products.name') }}</th>
                        <th class="p-3" scope="col">{{ t('products.sku') }}</th>
                        <th class="p-3" scope="col">{{ t('products.category') }}</th>
                        <th class="p-3 text-right" scope="col">{{ t('products.price') }}</th>
                        <th v-if="products.data[0].purchase_cost !== null" class="p-3 text-right" scope="col">{{ t('products.cost') }}</th>
                        <th class="p-3 text-right" scope="col">{{ t('products.stock') }}</th>
                        <th class="p-3" scope="col">{{ t('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id" class="border-b border-line last:border-0 hover:bg-surface">
                        <td class="p-3"><img v-if="p.thumb" :src="p.thumb" alt="" class="h-10 w-10 rounded object-cover" width="40" height="40" loading="lazy" /></td>
                        <td class="p-3 font-medium"><Link :href="`/app/products/${p.id}/edit`" class="hover:underline">{{ p.name }}</Link></td>
                        <td class="p-3 text-muted">{{ p.sku }}</td>
                        <td class="p-3 text-muted">{{ p.category ?? '—' }}</td>
                        <td class="p-3 text-right">{{ formatBdt(p.selling_price) }}</td>
                        <td v-if="p.purchase_cost !== null" class="p-3 text-right">{{ formatBdt(p.purchase_cost) }}</td>
                        <td class="p-3 text-right">{{ p.on_hand }}</td>
                        <td class="p-3"><StatusBadge :tone="tone(p.status)">{{ t(`products.${p.status}`) }}</StatusBadge></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="products" />
    </div>
</template>
