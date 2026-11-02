<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import { useTenant } from '@/composables/useTenant';
import AppLayout from '@/Layouts/AppLayout.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { purgeClientData } from '@/lib/purge';

// With no active workspace the app shell has nothing to show, so use the plain layout.
const { tenant, tenants } = useTenant();
defineOptions({ layout: (h: any, page: any) => h(page.props.tenant ? AppLayout : AuthLayout, () => page) });

const { t } = useI18n();
const form = useForm({ name: '' });
const create = () => form.post('/app/workspaces', { onSuccess: () => purgeClientData() });

const opening = ref<number | null>(null);
const error = ref('');
const busy = computed(() => opening.value !== null);

/** The whole card is the button: tapping a workspace anywhere on it opens it. */
async function open(id: number) {
    if (busy.value || id === tenant.value?.id) return;
    opening.value = id;
    error.value = '';
    await purgeClientData(); // nothing from the previous workspace may remain
    router.post(`/app/workspaces/${id}/switch`, {}, {
        onError: () => (error.value = t('workspace.openFailed')),
        onFinish: () => { opening.value = null; void purgeClientData(); },
    });
}
</script>

<template>
    <div class="mx-auto w-full max-w-xl space-y-6">
        <div>
            <h1 class="page-title">{{ t('workspace.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ tenants.length ? t('workspace.choose') : t('workspace.empty') }}</p>
        </div>

        <p v-if="error" role="alert" class="rounded-xl border border-danger/30 bg-danger/10 px-4 py-2.5 text-sm">{{ error }}</p>

        <ul v-if="tenants.length" class="space-y-2.5" data-testid="workspace-list">
            <li v-for="w in tenants" :key="w.id">
                <button
                    type="button" :disabled="busy && opening !== w.id" :aria-busy="opening === w.id" data-testid="workspace-card"
                    class="card group flex w-full touch-target items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:border-brand/50 disabled:cursor-wait disabled:opacity-60"
                    @click="open(w.id)"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-base font-bold text-white" style="background-image: var(--grad-brand)">{{ w.name[0] }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{ w.name }}</span>
                        <span class="text-xs text-muted">{{ w.id === tenant?.id ? t('workspace.current') : opening === w.id ? t('workspace.opening') : t('workspace.open') }}</span>
                    </span>
                    <NavIcon :name="w.id === tenant?.id ? 'check' : 'chevron'" :size="18" :class="w.id === tenant?.id ? 'text-brand' : '-rotate-90 text-muted transition group-hover:translate-x-0.5 group-hover:text-brand'" />
                </button>
            </li>
        </ul>

        <form class="card space-y-3 p-5" novalidate @submit.prevent="create">
            <label class="label" for="ws-name">{{ t('workspace.name') }}</label>
            <input id="ws-name" v-model="form.name" class="field" required />
            <p v-if="form.errors.name" class="err" role="alert">{{ form.errors.name }}</p>
            <OfflineGuard type="submit" :pending="form.processing" button-class="btn-secondary w-full"><NavIcon name="plus" :size="16" /> {{ t('workspace.create') }}</OfflineGuard>
        </form>
    </div>
</template>
