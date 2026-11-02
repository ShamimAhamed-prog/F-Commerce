<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });
const { t } = useI18n();
const form = useForm({ email: '' });
const sent = computed(() => (usePage().props as any).flash?.success);
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="form.post('/auth/forgot-password')">
        <h2 class="text-xl font-semibold">{{ t('auth.resetTitle') }}</h2>
        <p v-if="sent" class="rounded-lg bg-ok/15 px-3 py-2 text-sm" role="status">{{ sent }}</p>
        <div><label class="label" for="email">{{ t('auth.email') }}</label><input id="email" v-model="form.email" class="field" type="email" inputmode="email" autocomplete="username" required />
            <p v-if="form.errors.email" class="err" role="alert">{{ form.errors.email }}</p></div>
        <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary w-full">{{ t('auth.sendLink') }}</OfflineGuard>
        <p class="text-center text-sm"><Link href="/auth/login" class="text-brand underline">{{ t('auth.login') }}</Link></p>
    </form>
</template>
