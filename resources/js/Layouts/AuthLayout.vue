<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ConnectionStatus from '@/Components/ConnectionStatus.vue';
import InstallPrompt from '@/Components/InstallPrompt.vue';
import NavIcon from '@/Components/NavIcon.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const { t } = useI18n();
const points = [
    { icon: 'receipt', text: 'Orders, customers and invoices in one place' },
    { icon: 'truck', text: 'Courier bookings and COD reconciliation' },
    { icon: 'book', text: 'Double-entry accounting that stays accurate' },
];
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-[1.05fr_1fr]" style="padding-bottom: var(--safe-bottom)">
        <!-- Brand panel (desktop) -->
        <aside class="relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between" style="background-image: var(--grad-brand)">
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-black/10 blur-3xl"></div>
            <div class="relative flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-white/20 backdrop-blur"><NavIcon name="cart" :size="22" /></span><span class="text-2xl font-bold tracking-tight">{{ t('app.name') }}</span></div>
            <div class="relative max-w-md">
                <h2 class="text-4xl font-bold leading-tight tracking-tight">{{ t('app.tagline') }}</h2>
                <ul class="mt-8 space-y-4">
                    <li v-for="p in points" :key="p.text" class="flex items-center gap-3 text-white/90"><span class="grid h-9 w-9 place-items-center rounded-xl bg-white/15"><NavIcon :name="p.icon" :size="18" /></span>{{ p.text }}</li>
                </ul>
            </div>
            <p class="relative text-sm text-white/70">Built for sellers in Bangladesh · bKash, Nagad, COD ready</p>
        </aside>

        <div class="flex flex-col" style="padding-top: var(--safe-top)">
            <ConnectionStatus variant="banner" />
            <div class="flex items-center justify-between px-5 py-4">
                <span class="flex items-center gap-2 font-bold lg:invisible"><span class="grid h-8 w-8 place-items-center rounded-lg text-white" style="background-image: var(--grad-brand)"><NavIcon name="cart" :size="16" /></span>{{ t('app.name') }}</span>
                <ThemeToggle />
            </div>
            <main class="rise mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 pb-10">
                <div class="card p-6 sm:p-8"><slot /></div>
            </main>
            <InstallPrompt />
        </div>
    </div>
</template>
