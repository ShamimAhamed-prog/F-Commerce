<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });
const props = defineProps<{ token: string; email: string }>();
const { t } = useI18n();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="form.post('/auth/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
        <h2 class="text-xl font-semibold">{{ t('auth.resetTitle') }}</h2>
        <div><label class="label" for="email">{{ t('auth.email') }}</label><input id="email" v-model="form.email" class="field" type="email" autocomplete="username" />
            <p v-if="form.errors.email" class="err" role="alert">{{ form.errors.email }}</p></div>
        <div><label class="label" for="pw">{{ t('auth.newPassword') }}</label><input id="pw" v-model="form.password" class="field" type="password" autocomplete="new-password" />
            <p v-if="form.errors.password" class="err" role="alert">{{ form.errors.password }}</p></div>
        <div><label class="label" for="pw2">{{ t('auth.passwordConfirm') }}</label><input id="pw2" v-model="form.password_confirmation" class="field" type="password" autocomplete="new-password" /></div>
        <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('auth.resetPassword') }}</OfflineGuard>
    </form>
</template>
