<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{ meta: { current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null } }>();
const { t } = useI18n();
</script>

<template>
    <nav v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between gap-3" aria-label="Pagination">
        <Link v-if="meta.prev_page_url" :href="meta.prev_page_url" class="btn-secondary" preserve-scroll>{{ t('common.previous') }}</Link>
        <span v-else class="btn-secondary pointer-events-none opacity-40" aria-disabled="true">{{ t('common.previous') }}</span>
        <span class="text-sm text-muted">{{ t('common.page', { page: meta.current_page, last: meta.last_page }) }}</span>
        <Link v-if="meta.next_page_url" :href="meta.next_page_url" class="btn-secondary" preserve-scroll>{{ t('common.next') }}</Link>
        <span v-else class="btn-secondary pointer-events-none opacity-40" aria-disabled="true">{{ t('common.next') }}</span>
    </nav>
</template>
