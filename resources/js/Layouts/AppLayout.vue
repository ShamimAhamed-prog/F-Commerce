<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import ConnectionStatus from '@/Components/ConnectionStatus.vue';
import InstallPrompt from '@/Components/InstallPrompt.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import UpdatePrompt from '@/Components/UpdatePrompt.vue';
import { setLakh } from '@/composables/useCurrency';
import { usePermissions } from '@/composables/usePermissions';
import { useTenant } from '@/composables/useTenant';
import { purgeClientData } from '@/lib/purge';
import { GROUPS, MORE_TAB, NAV, isActive } from './nav';

const { t, locale } = useI18n();
const page = usePage();
const { tenant, tenants, user } = useTenant();
const { can } = usePermissions();
const items = computed(() => NAV.filter((i) => !i.permission || can(i.permission)));
const groups = computed(() => GROUPS.map((g) => ({ ...g, items: items.value.filter((i) => i.group === g.key) })).filter((g) => g.items.length));
const tabs = computed(() => [...items.value.filter((i) => i.tab), MORE_TAB]);
const flash = computed(() => (page.props as any).flash ?? {});
const active = (i: { href: string; match?: string }) => isActive(i, page.url);
const moreActive = computed(() => !items.value.some((i) => i.tab && active(i)));
const initials = computed(() => (user.value?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase());

watchEffect(() => {
    locale.value = (page.props as any).locale ?? 'en';
    setLakh(!!tenant.value?.lakh);
});

const menu = ref<'workspace' | 'user' | null>(null);
const root = ref<HTMLElement | null>(null);
const close = (e: Event) => { if (root.value && !root.value.contains(e.target as Node)) menu.value = null; };
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));
router.on('navigate', () => (menu.value = null));

async function logout() {
    await purgeClientData(); // SEC-P4
    router.post('/auth/logout', {}, { onFinish: () => purgeClientData() });
}
async function switchTo(id: number) {
    await purgeClientData(); // SEC-P4: nothing from the previous tenant may remain
    router.post(`/app/workspaces/${id}/switch`, {}, { onSuccess: () => (menu.value = null), onFinish: () => purgeClientData() });
}
</script>

<template>
    <div class="min-h-dvh lg:flex">
        <!-- Desktop sidebar (>=1024) -->
        <aside class="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r border-line bg-card/70 backdrop-blur lg:flex">
            <Link href="/app/dashboard" class="flex items-center gap-3 px-5 py-5">
                <span class="grid h-9 w-9 place-items-center rounded-xl text-white shadow-lg" style="background-image: var(--grad-brand)"><NavIcon name="cart" :size="18" /></span>
                <span class="text-lg font-bold tracking-tight">{{ t('app.name') }}</span>
            </Link>
            <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-4" aria-label="Primary">
                <div v-for="g in groups" :key="g.key">
                    <p class="eyebrow px-3 pb-1.5">{{ t(g.label) }}</p>
                    <Link v-for="i in g.items" :key="i.key" :href="i.href" class="group relative flex touch-target items-center gap-3 rounded-xl px-3 text-sm font-medium transition"
                        :class="active(i) ? 'bg-brand-soft text-brand' : 'text-muted hover:bg-surface-2 hover:text-ink'" :aria-current="active(i) ? 'page' : undefined">
                        <span v-if="active(i)" class="absolute inset-y-2 left-0 w-1 rounded-r-full" style="background-image: var(--grad-brand)"></span>
                        <NavIcon :name="i.icon" /> {{ t(`nav.${i.key}`) }}
                    </Link>
                </div>
            </nav>
            <div class="border-t border-line p-3">
                <div class="flex items-center gap-3 rounded-xl p-2">
                    <span class="grid h-9 w-9 place-items-center rounded-full text-xs font-bold text-white" style="background-image: var(--grad-brand)">{{ initials }}</span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">{{ user?.name }}</span><span class="block truncate text-xs text-muted">{{ user?.email }}</span></span>
                </div>
            </div>
        </aside>

        <!-- Tablet rail (640-1023) -->
        <aside class="sticky top-0 hidden h-dvh w-[4.5rem] shrink-0 flex-col items-center gap-1 overflow-y-auto border-r border-line bg-card/70 py-3 backdrop-blur sm:flex lg:hidden">
            <span class="mb-2 grid h-9 w-9 place-items-center rounded-xl text-white" style="background-image: var(--grad-brand)"><NavIcon name="cart" :size="18" /></span>
            <Link v-for="i in items" :key="i.key" :href="i.href" :title="t(`nav.${i.key}`)" class="grid h-11 w-11 place-items-center rounded-xl transition"
                :class="active(i) ? 'bg-brand-soft text-brand' : 'text-muted hover:bg-surface-2 hover:text-ink'" :aria-current="active(i) ? 'page' : undefined">
                <NavIcon :name="i.icon" /><span class="sr-only">{{ t(`nav.${i.key}`) }}</span>
            </Link>
        </aside>

        <div ref="root" class="flex min-w-0 flex-1 flex-col">
            <!-- Top bar: workspace switcher, connection, theme, user menu -->
            <header class="glass sticky top-0 z-30 border-b border-line" style="padding-top: var(--safe-top)">
                <div class="flex h-16 items-center justify-between gap-3 px-4 sm:px-6">
                    <div class="relative min-w-0">
                        <button class="flex touch-target max-w-[62vw] items-center gap-2.5 rounded-xl px-2 py-1.5 text-left transition hover:bg-surface-2" :aria-expanded="menu === 'workspace'" aria-haspopup="menu" @click.stop="menu = menu === 'workspace' ? null : 'workspace'">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-soft text-sm font-bold text-brand">{{ (tenant?.name ?? '?')[0] }}</span>
                            <span class="min-w-0"><span class="block truncate text-sm font-semibold leading-tight">{{ tenant?.name }}</span><span class="block text-[11px] leading-tight text-muted">{{ t('workspace.switch') }}</span></span>
                            <NavIcon name="chevron" :size="14" />
                        </button>
                        <div v-if="menu === 'workspace'" role="menu" class="card absolute left-0 top-full z-40 mt-2 w-72 max-w-[90vw] p-1.5" style="box-shadow: var(--shadow-pop)">
                            <p class="eyebrow px-3 py-2">{{ t('nav.workspaces') }}</p>
                            <button v-for="w in tenants" :key="w.id" role="menuitem" class="flex w-full touch-target items-center justify-between rounded-xl px-3 text-left text-sm hover:bg-surface-2" :disabled="w.id === tenant?.id" @click="switchTo(w.id)">
                                <span class="truncate">{{ w.name }}</span><NavIcon v-if="w.id === tenant?.id" name="check" :size="16" />
                            </button>
                            <Link href="/app/workspaces" class="mt-1 flex touch-target items-center gap-2 rounded-xl px-3 text-sm text-brand hover:bg-surface-2"><NavIcon name="plus" :size="16" /> {{ t('workspace.create') }}</Link>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <ConnectionStatus variant="chip" />
                        <div class="hidden sm:block"><ThemeToggle /></div>
                        <div class="relative">
                            <button class="grid h-10 w-10 place-items-center rounded-full text-xs font-bold text-white shadow" style="background-image: var(--grad-brand)" :aria-expanded="menu === 'user'" aria-haspopup="menu" :aria-label="user?.name" @click.stop="menu = menu === 'user' ? null : 'user'">{{ initials }}</button>
                            <div v-if="menu === 'user'" role="menu" class="card absolute right-0 top-full z-40 mt-2 w-64 p-2" style="box-shadow: var(--shadow-pop)">
                                <div class="px-3 py-2"><p class="truncate text-sm font-semibold">{{ user?.name }}</p><p class="truncate text-xs text-muted">{{ user?.email }}</p></div>
                                <div class="flex items-center justify-between px-3 py-2 sm:hidden"><span class="text-sm">{{ t('theme.label') }}</span><ThemeToggle /></div>
                                <OfflineGuard button-class="btn-ghost w-full !justify-start" @click="logout"><NavIcon name="logout" :size="18" /> {{ t('auth.logout') }}</OfflineGuard>
                            </div>
                        </div>
                    </div>
                </div>
                <ConnectionStatus variant="banner" />
            </header>

            <p v-if="flash.success" role="status" class="mx-4 mt-4 flex items-center gap-2 rounded-xl border border-ok/30 bg-ok/10 px-4 py-2.5 text-sm text-ink sm:mx-6"><NavIcon name="check" :size="16" class="text-ok" /> {{ flash.success }}</p>
            <p v-if="flash.error" role="alert" class="mx-4 mt-4 flex items-center gap-2 rounded-xl border border-danger/30 bg-danger/10 px-4 py-2.5 text-sm text-ink sm:mx-6"><NavIcon name="alert" :size="16" class="text-danger" /> {{ flash.error }}</p>

            <main class="rise flex-1 px-4 py-6 sm:px-6 lg:px-8" style="padding-bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 1.5rem)">
                <slot />
            </main>
            <InstallPrompt />
        </div>

        <UpdatePrompt />

        <!-- Mobile floating tab bar (<640) -->
        <nav class="glass fixed inset-x-3 z-30 flex rounded-2xl border border-line sm:hidden" style="bottom: calc(var(--safe-bottom) + 0.5rem); box-shadow: var(--shadow-pop)" aria-label="Primary">
            <Link v-for="i in tabs" :key="i.key" :href="i.href" class="relative flex flex-1 touch-target flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium transition"
                :style="{ minHeight: 'calc(var(--tabbar-h) - 0.5rem)' }" :class="(i.key === 'more' ? moreActive : active(i)) ? 'text-brand' : 'text-muted'" :aria-current="(i.key === 'more' ? moreActive : active(i)) ? 'page' : undefined">
                <span v-if="i.key === 'more' ? moreActive : active(i)" class="absolute top-0 h-1 w-8 rounded-b-full" style="background-image: var(--grad-brand)"></span>
                <NavIcon :name="i.icon" /><span>{{ t(`nav.${i.key}`) }}</span>
            </Link>
        </nav>
    </div>
</template>
