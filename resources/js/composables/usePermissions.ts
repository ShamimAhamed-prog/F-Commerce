import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types';

export function usePermissions() {
    const page = usePage<SharedProps>();
    const list = computed(() => page.props.permissions ?? []);

    return {
        can: (permission: string) => list.value.includes(permission),
        canAny: (...perms: string[]) => perms.some((p) => list.value.includes(p)),
    };
}
