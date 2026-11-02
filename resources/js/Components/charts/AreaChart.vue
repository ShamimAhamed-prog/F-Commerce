<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';

/**
 * Dependency-free responsive area/line chart. Hover or keyboard focus shows a tooltip;
 * a visually hidden table mirrors the data for screen readers.
 */
const props = withDefaults(defineProps<{ points: { label: string; value: number }[]; height?: number; format?: (n: number) => string; color?: string; ariaLabel?: string }>(), {
    height: 240, format: (n: number) => String(n), color: 'var(--chart-1)', ariaLabel: 'Trend chart',
});
const uid = useId().replace(/:/g, '');
const box = ref<HTMLElement | null>(null);
const width = ref(600);
const hover = ref<number | null>(null);
let ro: ResizeObserver | null = null;
onMounted(() => {
    if (!box.value) return;
    width.value = box.value.clientWidth || 600;
    ro = new ResizeObserver(([e]) => (width.value = Math.max(240, e.contentRect.width)));
    ro.observe(box.value);
});
onBeforeUnmount(() => ro?.disconnect());

const pad = { l: 44, r: 12, t: 12, b: 26 };
const compact = (n: number) => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
const max = computed(() => {
    const m = Math.max(0, ...props.points.map((p) => p.value));
    if (!m) return 1;
    const mag = 10 ** Math.floor(Math.log10(m));
    return [1, 2, 2.5, 5, 10].map((f) => f * mag).find((v) => v >= m * 1.05) ?? 10 * mag; // "nice" axis top
});
const innerW = computed(() => width.value - pad.l - pad.r);
const innerH = computed(() => props.height - pad.t - pad.b);
const x = (i: number) => pad.l + (props.points.length <= 1 ? innerW.value / 2 : (i / (props.points.length - 1)) * innerW.value);
const y = (v: number) => pad.t + innerH.value - (v / max.value) * innerH.value;
const coords = computed(() => props.points.map((p, i) => [x(i), y(p.value)] as const));
const line = computed(() => {
    const c = coords.value;
    if (!c.length) return '';
    let d = `M${c[0][0]},${c[0][1]}`;
    for (let i = 1; i < c.length; i++) { // smooth monotone-ish curve through the points
        const mx = (c[i - 1][0] + c[i][0]) / 2;
        d += ` C${mx},${c[i - 1][1]} ${mx},${c[i][1]} ${c[i][0]},${c[i][1]}`;
    }
    return d;
});
const area = computed(() => (coords.value.length ? `${line.value} L${coords.value.at(-1)![0]},${pad.t + innerH.value} L${coords.value[0][0]},${pad.t + innerH.value} Z` : ''));
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => (max.value / 4) * i));
const labelEvery = computed(() => Math.max(1, Math.ceil(props.points.length / Math.max(2, Math.floor(innerW.value / 70)))));

function move(e: PointerEvent) {
    const r = (e.currentTarget as SVGElement).getBoundingClientRect();
    const px = e.clientX - r.left;
    hover.value = Math.max(0, Math.min(props.points.length - 1, Math.round(((px - pad.l) / innerW.value) * (props.points.length - 1))));
}
const tip = computed(() => (hover.value === null ? null : { p: props.points[hover.value], cx: x(hover.value), cy: y(props.points[hover.value].value) }));
function key(e: KeyboardEvent) {
    if (!props.points.length) return;
    const cur = hover.value ?? 0;
    if (e.key === 'ArrowRight') hover.value = Math.min(props.points.length - 1, cur + 1);
    else if (e.key === 'ArrowLeft') hover.value = Math.max(0, cur - 1);
    else if (e.key === 'Escape') hover.value = null;
    else return;
    e.preventDefault();
}
</script>

<template>
    <div ref="box" class="relative w-full min-w-0 select-none">
        <svg :viewBox="`0 0 ${width} ${height}`" :style="{ width: '100%', height: `${height}px` }" role="img" :aria-label="ariaLabel" tabindex="0" class="block overflow-visible" @pointermove="move" @pointerleave="hover = null" @keydown="key" @blur="hover = null">
            <defs>
                <linearGradient :id="`g${uid}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="color" stop-opacity="0.32" /><stop offset="100%" :stop-color="color" stop-opacity="0" /></linearGradient>
            </defs>
            <g>
                <line v-for="t in ticks" :key="t" :x1="pad.l" :x2="width - pad.r" :y1="y(t)" :y2="y(t)" stroke="var(--chart-grid)" stroke-dasharray="3 4" />
                <text v-for="t in ticks" :key="`l${t}`" :x="pad.l - 8" :y="y(t) + 4" text-anchor="end" font-size="11" fill="var(--c-muted)">{{ compact(t) }}</text>
            </g>
            <path :d="area" :fill="`url(#g${uid})`" />
            <path :d="line" fill="none" :stroke="color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <g v-for="(p, i) in points" :key="i">
                <text v-if="i % labelEvery === 0" :x="x(i)" :y="height - 6" text-anchor="middle" font-size="11" fill="var(--c-muted)">{{ p.label }}</text>
            </g>
            <g v-if="tip">
                <line :x1="tip.cx" :x2="tip.cx" :y1="pad.t" :y2="pad.t + innerH" stroke="var(--c-muted)" stroke-opacity="0.4" stroke-dasharray="3 3" />
                <circle :cx="tip.cx" :cy="tip.cy" r="5" fill="var(--c-card)" :stroke="color" stroke-width="2.5" />
            </g>
        </svg>
        <div v-if="tip" class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg border border-line bg-card px-2.5 py-1.5 text-xs shadow-lg" :style="{ left: `${Math.min(Math.max(tip.cx, 56), width - 56)}px`, top: `${tip.cy - 10}px` }" role="status">
            <span class="block text-muted">{{ tip.p.label }}</span><span class="font-semibold">{{ format(tip.p.value) }}</span>
        </div>
        <table class="sr-only"><caption>{{ ariaLabel }}</caption><tbody><tr v-for="(p, i) in points" :key="i"><th scope="row">{{ p.label }}</th><td>{{ format(p.value) }}</td></tr></tbody></table>
    </div>
</template>
