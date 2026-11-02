<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });
const props = defineProps<{ state: 'invalid' | 'join' | 'wrong_account' | 'login' | 'register'; token?: string; email?: string; workspace?: string; role?: string }>();
const { t } = useI18n();
const form = useForm({ name: '', password: '', password_confirmation: '' });
const accept = () => (props.state === 'register' ? form.post(`/invitations/${props.token}/accept`) : router.post(`/invitations/${props.token}/accept`));
</script>

<template>
    <div class="space-y-4">
        <p v-if="state === 'invalid'" role="alert">{{ t('invite.invalid') }}</p>
        <template v-else>
            <h2 class="text-xl font-semibold">{{ t('invite.title') }}</h2>
            <p class="text-sm">{{ t('invite.joinAs', { workspace, role }) }}</p>
            <p class="text-xs text-muted">{{ email }}</p>
            <p v-if="state === 'wrong_account'" class="rounded-lg bg-warn/15 px-3 py-2 text-sm" role="alert">{{ t('invite.wrong') }}</p>
            <p v-if="state === 'login'" class="text-sm">{{ t('invite.loginFirst', { email }) }}</p>
            <Link v-if="state === 'login'" href="/auth/login" class="btn-primary w-full">{{ t('auth.login') }}</Link>
            <OfflineGuard v-if="state === 'join'" button-class="btn-primary w-full" @click="accept">{{ t('invite.join') }}</OfflineGuard>
            <form v-if="state === 'register'" class="space-y-3" novalidate @submit.prevent="accept">
                <p class="text-sm font-medium">{{ t('invite.createAccount') }}</p>
                <div><label class="label" for="n">{{ t('auth.name') }}</label><input id="n" v-model="form.name" class="field" autocomplete="name" /><p v-if="form.errors.name" class="err" role="alert">{{ form.errors.name }}</p></div>
                <div><label class="label" for="p">{{ t('auth.password') }}</label><input id="p" v-model="form.password" class="field" type="password" autocomplete="new-password" /><p v-if="form.errors.password" class="err" role="alert">{{ form.errors.password }}</p></div>
                <div><label class="label" for="p2">{{ t('auth.passwordConfirm') }}</label><input id="p2" v-model="form.password_confirmation" class="field" type="password" autocomplete="new-password" /></div>
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('invite.join') }}</OfflineGuard>
            </form>
        </template>
    </div>
</template>
