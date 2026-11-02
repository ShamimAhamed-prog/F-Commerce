<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

/**
 * Modal that is a bottom sheet on phones and a centred dialog on larger screens (UI-7).
 * Built on <dialog>: focus trapping, ESC and inert background come from the platform.
 */
const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();
const el = ref<HTMLDialogElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        await nextTick();
        if (open && !el.value?.open) el.value?.showModal();
        if (!open && el.value?.open) el.value.close();
    },
    { immediate: true },
);
</script>

<template>
    <dialog
        ref="el"
        class="m-0 mt-auto w-full max-w-none rounded-t-2xl border border-line bg-card p-0 text-ink backdrop:bg-black/50 sm:m-auto sm:max-w-lg sm:rounded-2xl"
        style="max-height: 92dvh"
        :aria-label="title"
        @cancel.prevent="emit('close')"
        @click.self="emit('close')"
    >
        <div v-if="open" class="flex max-h-[92dvh] flex-col">
            <header class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <h2 class="text-base font-semibold">{{ title }}</h2>
                <button type="button" class="btn-secondary !px-3" @click="emit('close')">✕<span class="sr-only">Close</span></button>
            </header>
            <div class="overflow-y-auto px-4 py-4" style="padding-bottom: calc(1rem + var(--safe-bottom))"><slot /></div>
        </div>
    </dialog>
</template>
