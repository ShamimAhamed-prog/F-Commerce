<script setup lang="ts">
import NavIcon from '@/Components/NavIcon.vue';
import Sparkline from '@/Components/charts/Sparkline.vue';

withDefaults(defineProps<{ label: string; value: string; icon: string; delta?: number | null; hint?: string; spark?: number[]; tone?: 'brand' | 'ok' | 'warn' | 'danger' | 'info'; href?: string }>(), { tone: 'brand', delta: null });
const tones: Record<string, string> = {
    brand: 'bg-brand-soft text-brand', ok: 'bg-ok/12 text-ok', warn: 'bg-warn/12 text-warn', danger: 'bg-danger/12 text-danger', info: 'bg-info/12 text-info',
};
</script>

<template>
    <component :is="href ? 'a' : 'div'" :href="href" class="card group relative block overflow-hidden p-4 transition hover:-translate-y-0.5 sm:p-5" :data-testid="`stat-${label}`">
        <div class="flex items-start justify-between gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-xl" :class="tones[tone]"><NavIcon :name="icon" :size="19" /></span>
            <span v-if="delta !== null" class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold" :class="delta >= 0 ? 'bg-ok/12 text-ok' : 'bg-danger/12 text-danger'">
                <NavIcon :name="delta >= 0 ? 'arrowUp' : 'arrowDown'" :size="12" />{{ Math.abs(delta) }}%
            </span>
        </div>
        <p class="eyebrow mt-4">{{ label }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular sm:text-[1.7rem]">{{ value }}</p>
        <div class="mt-1 flex items-end justify-between gap-2">
            <p class="min-w-0 text-xs text-muted">{{ hint }}</p>
            <Sparkline v-if="spark && spark.length > 1" :values="spark" class="hidden shrink-0 sm:block" />
        </div>
    </component>
</template>
