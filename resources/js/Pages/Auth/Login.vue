<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });
const { t } = useI18n();
const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/auth/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <h2 class="text-xl font-semibold">{{ t('auth.login') }}</h2>
        <div>
            <label class="label" for="email">{{ t('auth.email') }}</label>
            <input id="email" v-model="form.email" class="field" type="email" inputmode="email" autocomplete="username" required />
            <p v-if="form.errors.email" class="err" role="alert">{{ form.errors.email }}</p>
        </div>
        <div>
            <label class="label" for="password">{{ t('auth.password') }}</label>
            <input id="password" v-model="form.password" class="field" type="password" autocomplete="current-password" required />
            <p v-if="form.errors.password" class="err" role="alert">{{ form.errors.password }}</p>
        </div>
        <label class="flex touch-target items-center gap-2 text-sm">
            <input v-model="form.remember" type="checkbox" class="h-5 w-5" /> {{ t('auth.remember') }}
        </label>
        <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('auth.login') }}</OfflineGuard>
        <p class="text-center text-sm"><Link href="/auth/forgot-password" class="text-brand underline">{{ t('auth.forgot') }}</Link></p>
        <p class="text-center text-sm"><Link href="/auth/register" class="text-brand underline">{{ t('auth.noAccount') }}</Link></p>
    </form>
</template>
