<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
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

async function open(id: number) {
    await purgeClientData();
    router.post(`/app/workspaces/${id}/switch`, {}, { onFinish: () => purgeClientData() });
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <h1 class="text-xl font-bold">{{ t('workspace.title') }}</h1>
            <p class="text-sm text-muted">{{ tenants.length ? t('workspace.choose') : t('workspace.empty') }}</p>
        </div>

        <ul v-if="tenants.length" class="space-y-2">
            <li v-for="w in tenants" :key="w.id" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
                <span class="truncate font-medium">{{ w.name }}</span>
                <OfflineGuard v-if="w.id !== tenant?.id" button-class="btn-secondary !px-3" @click="open(w.id)">{{ t('workspace.open') }}</OfflineGuard>
                <span v-else class="text-xs text-brand">✓</span>
            </li>
        </ul>

        <form class="space-y-3 rounded-xl border border-line bg-card p-4" novalidate @submit.prevent="create">
            <label class="label" for="ws-name">{{ t('workspace.name') }}</label>
            <input id="ws-name" v-model="form.name" class="field" required />
            <p v-if="form.errors.name" class="err" role="alert">{{ form.errors.name }}</p>
            <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('workspace.create') }}</OfflineGuard>
        </form>
    </div>
</template>
