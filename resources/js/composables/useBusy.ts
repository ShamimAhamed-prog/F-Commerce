import { computed, reactive } from 'vue';

/**
 * Registry of in-progress work (dirty forms, active uploads). The service-worker
 * update is deferred while anything is registered here (PWA-20).
 */
const active = reactive(new Set<string>());

export const isBusy = computed(() => active.size > 0);

export function markBusy(key: string) {
    active.add(key);
}

export function markIdle(key: string) {
    active.delete(key);
}
