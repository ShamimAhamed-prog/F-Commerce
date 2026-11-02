import { onBeforeUnmount, watch, type Ref } from 'vue';
import { markBusy, markIdle } from './useBusy';

/** Registers a dirty form as "busy" so a waiting service-worker update is deferred (PWA-20). */
export function useDirtyGuard(key: string, dirty: Ref<boolean>) {
    watch(dirty, (d) => (d ? markBusy(key) : markIdle(key)), { immediate: true });
    onBeforeUnmount(() => markIdle(key));
}
