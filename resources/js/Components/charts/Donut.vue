<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ segments: { label: string; value: number; color: string }[]; size?: number; thickness?: number; center?: string; sub?: string }>(), { size: 168, thickness: 18, center: '', sub: '' });
const r = computed(() => (props.size - props.thickness) / 2);
const c = computed(() => 2 * Math.PI * r.value);
const total = computed(() => props.segments.reduce((a, s) => a + s.value, 0));
const arcs = computed(() => {
    let off = 0;
    return props.segments.filter((s) => s.value > 0).map((s) => {
        const len = (s.value / total.value) * c.value;
        const a = { ...s, dash: `${Math.max(0, len - 2)} ${c.value - Math.max(0, len - 2)}`, offset: -off };
        off += len;
        return a;
    });
});
</script>

<template>
    <div class="relative inline-grid place-items-center" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg :width="size" :height="size" role="img" :aria-label="segments.map((s) => `${s.label}: ${s.value}`).join(', ') || 'No data'" class="-rotate-90">
            <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" stroke="var(--chart-grid)" :stroke-width="thickness" />
            <circle v-for="a in arcs" :key="a.label" :cx="size / 2" :cy="size / 2" :r="r" fill="none" :stroke="a.color" :stroke-width="thickness" stroke-linecap="round" :stroke-dasharray="a.dash" :stroke-dashoffset="a.offset" style="transition: stroke-dasharray 0.4s" />
        </svg>
        <div class="absolute text-center"><p class="text-2xl font-bold tabular leading-none">{{ center }}</p><p class="mt-1 text-xs text-muted">{{ sub }}</p></div>
    </div>
</template>
