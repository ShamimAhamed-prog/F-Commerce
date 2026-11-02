<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
defineProps<{ roles: { name: string; slug: string; permissions: string[] }[]; all: string[] }>();
const { t } = useI18n();
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2"><div><h1 class="text-xl font-bold">{{ t('settings.rolesTitle') }}</h1><p class="text-sm text-muted">{{ t('settings.rolesHelp') }}</p></div>
            <Link href="/app/settings/staff" class="btn-secondary">{{ t('common.back') }}</Link></div>
        <div class="overflow-x-auto rounded-xl border border-line bg-card">
            <table class="w-full text-xs">
                <thead class="border-b border-line text-left"><tr><th class="sticky left-0 bg-card p-2" scope="col">Permission</th><th v-for="r in roles" :key="r.slug" class="p-2" scope="col">{{ r.name }}</th></tr></thead>
                <tbody><tr v-for="p in all" :key="p" class="border-b border-line last:border-0"><th class="sticky left-0 bg-card p-2 text-left font-normal" scope="row">{{ p }}</th>
                    <td v-for="r in roles" :key="r.slug" class="p-2 text-center"><span v-if="r.permissions.includes(p)" aria-label="allowed">✓</span><span v-else class="text-muted" aria-label="not allowed">–</span></td></tr></tbody>
            </table>
        </div>
    </div>
</template>
