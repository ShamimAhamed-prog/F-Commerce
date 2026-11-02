<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ tone?: 'ok' | 'warn' | 'danger' | 'muted' }>();
const cls = computed(
    () =>
        ({
            ok: 'bg-ok/15 text-ok',
            warn: 'bg-warn/15 text-warn',
            danger: 'bg-danger/15 text-danger',
            muted: 'bg-line text-muted',
        })[props.tone ?? 'muted'],
);
</script>

<template><span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="cls"><slot /></span></template>
