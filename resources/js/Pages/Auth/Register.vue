<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });
const { t } = useI18n();
const form = useForm({
    name: '',
    email: '',
    phone: '',
    workspace_name: '',
    password: '',
    password_confirmation: '',
});
const submit = () => form.post('/auth/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <h2 class="text-xl font-semibold">{{ t('auth.register') }}</h2>
        <div>
            <label class="label" for="name">{{ t('auth.name') }}</label>
            <input id="name" v-model="form.name" class="field" autocomplete="name" required />
            <p v-if="form.errors.name" class="err" role="alert">{{ form.errors.name }}</p>
        </div>
        <div>
            <label class="label" for="workspace">{{ t('auth.workspaceName') }}</label>
            <input id="workspace" v-model="form.workspace_name" class="field" autocomplete="organization" required />
            <p v-if="form.errors.workspace_name" class="err" role="alert">{{ form.errors.workspace_name }}</p>
        </div>
        <div>
            <label class="label" for="email">{{ t('auth.email') }}</label>
            <input id="email" v-model="form.email" class="field" type="email" inputmode="email" autocomplete="email" required />
            <p v-if="form.errors.email" class="err" role="alert">{{ form.errors.email }}</p>
        </div>
        <div>
            <label class="label" for="phone">{{ t('auth.phone') }}</label>
            <input id="phone" v-model="form.phone" class="field" type="tel" inputmode="tel" autocomplete="tel" />
            <p v-if="form.errors.phone" class="err" role="alert">{{ form.errors.phone }}</p>
        </div>
        <div>
            <label class="label" for="password">{{ t('auth.password') }}</label>
            <input id="password" v-model="form.password" class="field" type="password" autocomplete="new-password" required />
            <p v-if="form.errors.password" class="err" role="alert">{{ form.errors.password }}</p>
        </div>
        <div>
            <label class="label" for="password_confirmation">{{ t('auth.passwordConfirm') }}</label>
            <input id="password_confirmation" v-model="form.password_confirmation" class="field" type="password" autocomplete="new-password" required />
        </div>
        <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('auth.register') }}</OfflineGuard>
        <p class="text-center text-sm"><Link href="/auth/login" class="text-brand underline">{{ t('auth.haveAccount') }}</Link></p>
    </form>
</template>
