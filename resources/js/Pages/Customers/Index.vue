<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { telHref } from '@/lib/phone';

defineOptions({ layout: AppLayout });
const props = defineProps<{ customers: any; filters: { search?: string } }>();
const { t } = useI18n();
const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout>;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => router.get('/app/customers', { search: search.value || undefined }, { preserveState: true, replace: true }), 300); });
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-xl font-bold">{{ t('customers.title') }}</h1>
        <input v-model="search" type="search" class="field" :placeholder="t('orders.searchPlaceholder')" :aria-label="t('common.search')" />
        <p v-if="!customers.data.length" class="rounded-xl border border-line bg-card p-6 text-center text-sm text-muted">{{ t('customers.noCustomers') }}</p>
        <ul class="space-y-2">
            <li v-for="c in customers.data" :key="c.id" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                <Link :href="`/app/customers/${c.id}`" class="min-w-0"><span class="block truncate font-medium">{{ c.name }}</span><span class="text-xs text-muted">{{ c.phone }} · {{ t('customers.orders') }} {{ c.orders_count }}</span></Link>
                <a :href="telHref(c.phone)" class="btn-secondary shrink-0 !px-3" :aria-label="`${t('orders.call')} ${c.name}`">📞</a>
            </li>
        </ul>
        <Pagination :meta="customers" />
    </div>
</template>
