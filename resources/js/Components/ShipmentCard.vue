<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NavIcon from '@/Components/NavIcon.vue';
import OfflineGuard from '@/Components/OfflineGuard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps<{ shipment: any; canManage: boolean }>();
const { t } = useI18n();
const copied = ref(false);
const s = computed(() => props.shipment);

const stateTone = computed(() => ({ booked: 'ok', failed: 'danger', booking_unknown: 'warn', booking: 'warn' } as Record<string, any>)[s.value.state] ?? 'muted');
const stateLabel = computed(() => t(`ship.${s.value.state === 'booking_unknown' ? 'unknown' : s.value.state}`));
const statusLabel = computed(() => (s.value.provider_status ? (t(`ship.s_${s.value.provider_status}`) as string) : ''));
const statusTone = computed(() => {
    const p = s.value.provider_status ?? '';
    if (p === 'delivered' || p === 'partial_delivered') return 'ok';
    if (p.startsWith('cancelled') || p === 'exceptional' || p === 'hold') return 'danger';
    return 'warn';
});
async function copy() {
    try { await navigator.clipboard.writeText(s.value.tracking_code ?? s.value.consignment_id); copied.value = true; setTimeout(() => (copied.value = false), 1500); } catch { /* clipboard unavailable */ }
}
const refresh = () => router.post(`/app/shipments/${s.value.id}/refresh`, {}, { preserveScroll: true });
const reconcile = () => router.post(`/app/shipments/${s.value.id}/reconcile`, {}, { preserveScroll: true });
</script>

<template>
    <div class="space-y-3" data-testid="shipment-card">
        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :tone="stateTone">{{ stateLabel }}</StatusBadge>
            <StatusBadge v-if="statusLabel" :tone="statusTone">{{ statusLabel }}</StatusBadge>
            <span class="text-xs text-muted">{{ s.invoice }}</span>
        </div>

        <dl v-if="s.state === 'booked'" class="grid gap-3 text-sm sm:grid-cols-2">
            <div v-if="s.tracking_code || s.consignment_id"><dt class="eyebrow">{{ t('ship.tracking') }}</dt>
                <dd class="mt-0.5 flex items-center gap-2"><span class="font-mono text-base font-semibold" data-testid="tracking-code">{{ s.tracking_code ?? s.consignment_id }}</span>
                    <button type="button" class="btn-ghost !min-h-[36px] !px-2" :aria-label="t('ship.copy')" @click="copy"><NavIcon :name="copied ? 'check' : 'list'" :size="16" /> <span class="text-xs">{{ copied ? t('ship.copied') : t('ship.copy') }}</span></button></dd></div>
            <div v-if="s.consignment_id"><dt class="eyebrow">{{ t('ship.consignment') }}</dt><dd class="mt-0.5 font-mono">{{ s.consignment_id }}</dd></div>
            <div v-if="s.last_synced_at"><dt class="eyebrow">{{ t('ship.lastSynced') }}</dt><dd class="mt-0.5">{{ new Date(s.last_synced_at).toLocaleString() }}</dd></div>
        </dl>

        <p v-if="s.message" class="rounded-xl px-3 py-2 text-sm" :class="s.state === 'booking_unknown' ? 'bg-warn/10' : 'bg-danger/10'" role="status" data-testid="ship-message">{{ s.message }}</p>
        <p v-if="s.state === 'booking_unknown' || s.can_reconcile" class="text-sm font-medium">{{ t('ship.unknownHelp') }}</p>

        <div v-if="canManage" class="flex flex-wrap gap-2">
            <OfflineGuard v-if="s.state === 'booked'" button-class="btn-secondary" data-action="refresh" @click="refresh"><NavIcon name="zap" :size="16" /> {{ t('ship.refresh') }}</OfflineGuard>
            <OfflineGuard v-if="s.can_reconcile" button-class="btn-primary" data-action="reconcile" @click="reconcile">{{ t('ship.check') }}</OfflineGuard>
        </div>

        <details v-if="s.events?.length" class="text-sm"><summary class="cursor-pointer select-none text-muted">{{ t('ship.history') }}</summary>
            <ol class="mt-2 space-y-1.5 border-l-2 border-line pl-4">
                <li v-for="(e, i) in s.events" :key="i"><span class="font-medium">{{ e.status.replace(/_/g, ' ') }}</span> <span class="text-xs text-muted">{{ new Date(e.at).toLocaleString() }}<span v-if="e.note"> · {{ e.note }}</span></span></li>
            </ol></details>
    </div>
</template>
