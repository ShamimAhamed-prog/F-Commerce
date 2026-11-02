<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ members: any[]; invitations: any[]; roles: { id: number; slug: string; name: string }[]; me: number }>();
const { t } = useI18n();
const form = useForm({ email: '', role_id: props.roles.find((r) => r.slug === 'viewer')?.id ?? props.roles[0]?.id });
const invite = () => form.post('/app/settings/staff/invite', { preserveScroll: true, onSuccess: () => form.reset('email') });
const roleId = (slug: string) => props.roles.find((r) => r.slug === slug)?.id;
const change = (id: number, role_id: number) => router.patch(`/app/settings/staff/${id}`, { role_id }, { preserveScroll: true });
const remove = (id: number) => window.confirm(t('settings.confirmRemove')) && router.delete(`/app/settings/staff/${id}`, { preserveScroll: true });
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2"><h1 class="text-xl font-bold">{{ t('settings.team') }}</h1>
            <div class="flex gap-2"><Link href="/app/settings/roles" class="btn-secondary">{{ t('settings.viewRoles') }}</Link><Link href="/app/settings" class="btn-secondary">{{ t('nav.settings') }}</Link></div></div>

        <form class="space-y-3 rounded-xl border border-line bg-card p-4" novalidate @submit.prevent="invite">
            <h2 class="font-semibold">{{ t('settings.invite') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="em">{{ t('settings.inviteEmail') }}</label><input id="em" v-model="form.email" class="field" type="email" inputmode="email" />
                    <p v-if="form.errors.email" class="err" role="alert">{{ form.errors.email }}</p></div>
                <div><label class="label" for="ro">{{ t('settings.role') }}</label><select id="ro" v-model="form.role_id" class="field"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select></div>
            </div>
            <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary">{{ t('settings.invite') }}</OfflineGuard>
        </form>

        <section v-if="invitations.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('settings.pending') }}</h2>
            <ul class="space-y-2"><li v-for="i in invitations" :key="i.id" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-card p-3 text-sm">
                <span class="min-w-0"><span class="block truncate font-medium">{{ i.email }}</span><span class="text-xs text-muted">{{ i.role }} · {{ t('settings.expires') }} {{ new Date(i.expires_at).toLocaleDateString() }}</span></span>
                <OfflineGuard button-class="btn-secondary !px-3" @click="router.delete(`/app/settings/staff/invitations/${i.id}`, { preserveScroll: true })">{{ t('settings.revoke') }}</OfflineGuard></li></ul>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('settings.members') }}</h2>
            <ul class="space-y-2"><li v-for="m in members" :key="m.id" class="space-y-2 rounded-xl border border-line bg-card p-3 text-sm">
                <div class="flex items-center justify-between gap-2"><span class="min-w-0"><span class="block truncate font-medium">{{ m.name }} <StatusBadge v-if="m.id === me">{{ t('settings.you') }}</StatusBadge></span><span class="text-xs text-muted">{{ m.email }}</span></span></div>
                <div class="flex flex-wrap items-center gap-2">
                    <select :value="roleId(m.role)" class="field !w-auto" :aria-label="t('settings.role')" :disabled="!roleId(m.role)" @change="change(m.id, Number(($event.target as HTMLSelectElement).value))">
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        <option v-if="!roleId(m.role)" :value="undefined">{{ m.role_name }}</option>
                    </select>
                    <OfflineGuard button-class="btn-secondary !text-danger !px-3" @click="remove(m.id)">{{ t('settings.removeMember') }}</OfflineGuard>
                </div></li></ul>
        </section>
    </div>
</template>
