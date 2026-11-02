<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import { useTheme, type ThemeMode } from '@/composables/useTheme';

const { t } = useI18n();
const { mode, set } = useTheme();
const opts: { v: ThemeMode; icon: string; label: string }[] = [
    { v: 'light', icon: 'sun', label: 'theme.light' },
    { v: 'system', icon: 'monitor', label: 'theme.system' },
    { v: 'dark', icon: 'moon', label: 'theme.dark' },
];
</script>

<template>
    <div role="group" :aria-label="t('theme.label')" class="inline-flex rounded-xl border border-line bg-card p-0.5" data-testid="theme-toggle">
        <button
            v-for="o in opts" :key="o.v" type="button" :aria-pressed="mode === o.v" :title="t(o.label)" :data-theme-option="o.v"
            class="grid h-9 w-9 place-items-center rounded-[0.65rem] text-muted transition"
            :class="mode === o.v ? 'bg-brand-soft text-brand' : 'hover:text-ink'" @click="set(o.v)"
        >
            <NavIcon :name="o.icon" :size="16" /><span class="sr-only">{{ t(o.label) }}</span>
        </button>
    </div>
</template>
