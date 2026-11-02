<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(defineProps<{ values: number[]; width?: number; height?: number; color?: string }>(), { width: 96, height: 32, color: 'var(--c-brand)' });
const uid = useId().replace(/:/g, '');
const pts = computed(() => {
    const v = props.values.length > 1 ? props.values : [0, ...props.values, 0];
    const max = Math.max(...v, 1);
    const min = Math.min(...v, 0);
    return v.map((n, i) => [(i / (v.length - 1)) * props.width, props.height - 3 - ((n - min) / (max - min || 1)) * (props.height - 6)] as const);
});
const d = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' '));
</script>

<template>
    <svg :width="width" :height="height" aria-hidden="true" class="overflow-visible">
        <defs><linearGradient :id="`s${uid}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="color" stop-opacity="0.25" /><stop offset="1" :stop-color="color" stop-opacity="0" /></linearGradient></defs>
        <path :d="`${d} L${width},${height} L0,${height} Z`" :fill="`url(#s${uid})`" />
        <path :d="d" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
