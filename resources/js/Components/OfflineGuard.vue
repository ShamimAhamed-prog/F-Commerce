<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConnection } from '@/composables/useConnection';

/**
 * Wraps every mutating control (OFF-6). When offline / server unreachable it is
 * disabled WITH a visible reason; clicking never starts a request that could be
 * retried later. `pending` locks it during submit (OFF-11).
 */
const props = withDefaults(
    defineProps<{ type?: 'button' | 'submit'; pending?: boolean; disabled?: boolean; buttonClass?: string }>(),
    { type: 'button', pending: false, disabled: false, buttonClass: 'btn-primary' },
);
defineOptions({ inheritAttrs: false });
const { t } = useI18n();
const conn = useConnection();
const reasonId = useId();
const blocked = computed(() => conn.writesBlocked.value);
</script>

<template>
    <span class="inline-flex flex-col items-stretch gap-1" :class="{ 'w-full': props.buttonClass.includes('w-full') }">
        <button
            :type="props.type"
            :class="props.buttonClass"
            :disabled="props.disabled || props.pending || blocked"
            :aria-busy="props.pending || undefined"
            :aria-describedby="blocked ? reasonId : undefined"
            data-offline-guard
            v-bind="$attrs"
        >
            <slot />
        </button>
        <span v-if="blocked" :id="reasonId" class="text-center text-xs text-warn">{{ t('connection.needsInternet') }}</span>
    </span>
</template>
