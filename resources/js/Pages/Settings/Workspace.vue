<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import { useDirtyGuard } from '@/composables/useDirtyGuard';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });
const props = defineProps<{ workspace: any; maxImagesCap: number }>();
const { t } = useI18n();
const { can } = usePermissions();
const form = useForm({ ...props.workspace });
useDirtyGuard('settings', computed(() => form.isDirty));
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2"><h1 class="text-xl font-bold">{{ t('settings.title') }}</h1>
            <div class="flex gap-2"><Link v-if="can('staff.manage')" href="/app/settings/staff" class="btn-secondary">{{ t('nav.staff') }}</Link></div></div>
        <form class="space-y-4 pb-24" novalidate @submit.prevent="form.put('/app/settings', { preserveScroll: true })">
            <section class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('settings.business') }}</h2>
                <div><label class="label" for="n">{{ t('products.name') }}</label><input id="n" v-model="form.name" class="field" /><p v-if="err('name')" class="err" role="alert">{{ err('name') }}</p></div>
                <div><label class="label" for="ph">{{ t('orders.phone') }}</label><input id="ph" v-model="form.phone" class="field" type="tel" inputmode="tel" /></div>
                <div><label class="label" for="ad">{{ t('orders.address') }}</label><textarea id="ad" v-model="form.address" rows="2" class="field"></textarea></div>
                <div><label class="label" for="tz">{{ t('settings.timezone') }}</label><input id="tz" v-model="form.timezone" class="field" /><p v-if="err('timezone')" class="err" role="alert">{{ err('timezone') }}</p></div>
                <label class="flex touch-target items-center gap-2 text-sm"><input v-model="form.lakh_grouping" type="checkbox" class="h-5 w-5" /> {{ t('settings.lakh') }}</label>
            </section>
            <section class="space-y-4 rounded-xl border border-line bg-card p-4">
                <h2 class="font-semibold">{{ t('settings.catalog') }}</h2>
                <label class="flex touch-target items-center gap-2 text-sm"><input v-model="form.require_image_before_activation" type="checkbox" class="h-5 w-5" data-testid="require-image" /> {{ t('settings.requireImage') }}</label>
                <div><label class="label" for="mi">{{ t('settings.maxImages') }} (1–{{ maxImagesCap }})</label><input id="mi" v-model.number="form.max_images_per_product" class="field" inputmode="numeric" /><p v-if="err('max_images_per_product')" class="err" role="alert">{{ err('max_images_per_product') }}</p></div>
            </section>
            <div class="fixed inset-x-0 z-20 flex justify-end border-t border-line bg-card px-4 py-3 sm:static sm:border-0 sm:bg-transparent sm:p-0" style="bottom: calc(var(--tabbar-h) + var(--safe-bottom))">
                <OfflineGuard type="submit" :pending="form.processing" button-class="btn-primary">{{ t('common.save') }}</OfflineGuard>
            </div>
        </form>
    </div>
</template>
