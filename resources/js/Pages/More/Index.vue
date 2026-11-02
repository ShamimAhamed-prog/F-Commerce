<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { NAV } from '@/Layouts/nav';

defineOptions({ layout: AppLayout });
const { t } = useI18n();
const { can } = usePermissions();
const items = computed(() => NAV.filter((i) => !i.tab && (!i.permission || can(i.permission))));
</script>

<template>
    <h1 class="mb-3 text-xl font-bold">{{ t('more.title') }}</h1>
    <ul class="space-y-2">
        <li v-for="i in items" :key="i.key">
            <Link :href="i.href" class="flex touch-target items-center gap-3 rounded-xl border border-line bg-card p-3"><NavIcon :name="i.icon" /> {{ t(`nav.${i.key}`) }}</Link>
        </li>
    </ul>
</template>
