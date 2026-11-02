import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types';

export function useTenant() {
    const page = usePage<SharedProps>();

    return {
        tenant: computed(() => page.props.tenant),
        tenants: computed(() => page.props.tenants ?? []),
        user: computed(() => page.props.auth?.user ?? null),
    };
}
