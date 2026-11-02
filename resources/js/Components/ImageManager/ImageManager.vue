<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Sheet from '@/Components/Sheet.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { markBusy, markIdle } from '@/composables/useBusy';
import { useConnection } from '@/composables/useConnection';
import { downscale } from '@/lib/imageResize';
import { ConflictError, dropBefore, imagesApi, moveId, type ImageItem, type ImagePayload } from './api';

const props = defineProps<{
    productId: number;
    initial: ImagePayload;
    variants: { id: number; sku: string; options: Record<string, string> }[];
    canManage: boolean;
}>();

const { t } = useI18n();
const conn = useConnection();
const api = imagesApi(props.productId);
const blocked = computed(() => !props.canManage || conn.writesBlocked.value); // OFF-6

const items = ref<ImageItem[]>(props.initial.items);
const etag = ref(props.initial.etag);
const max = ref(props.initial.max);
const message = ref<string | null>(null);
const warnings = ref<string[]>([]);
const selecting = ref(false);
const selected = ref<Set<number>>(new Set());
const editing = ref<ImageItem | null>(null);
const busy = ref(false);

interface Upload { key: number; file: File; name: string; progress: number; state: 'queued' | 'uploading' | 'failed'; error?: string; ctrl?: AbortController }
const uploads = reactive<Upload[]>([]);
let keySeq = 0;
let running = false;

function apply(p: ImagePayload) {
    items.value = p.items;
    etag.value = p.etag;
    max.value = p.max;
}

// ---------------------------------------------------------------- upload
async function pick(files: FileList | File[] | null) {
    if (!files || blocked.value) return;
    message.value = null;
    const room = max.value - items.value.length - uploads.filter((u) => u.state !== 'failed').length;
    const list = Array.from(files).slice(0, Math.max(0, room));
    if (files.length > list.length) message.value = t('images.limit', { count: items.value.length, max: max.value });
    for (const f of list) uploads.push({ key: ++keySeq, file: f, name: f.name, progress: 0, state: 'queued' });
    void drain();
}

async function drain() {
    if (running) return;
    running = true;
    markBusy(`images:${props.productId}`); // defers SW updates while uploading
    try {
        // Pull the next queued item each pass: the list is mutated while we work (never iterate it directly).
        for (let u = uploads.find((x) => x.state === 'queued'); u; u = uploads.find((x) => x.state === 'queued')) {
            if (conn.writesBlocked.value) {
                u.state = 'failed'; // never queued for later: the user retries when online
                u.error = t('images.offline');
                continue;
            }
            u.state = 'uploading';
            u.ctrl = new AbortController();
            try {
                const file = await downscale(u.file);
                const p = await api.upload(file, (pct) => (u.progress = pct), u.ctrl.signal);
                warnings.value.push(...(p.warnings ?? []));
                apply(p);
                uploads.splice(uploads.indexOf(u), 1);
                startPolling();
            } catch (e: any) {
                if (e?.code === 'ERR_CANCELED' || e?.name === 'CanceledError') {
                    uploads.splice(uploads.indexOf(u), 1);
                } else {
                    u.state = 'failed';
                    u.error = (e as any).network ? t('images.uploadFailed') : e.message;
                    if ((e as any).network) void conn.reportFailure();
                }
            }
        }
    } finally {
        running = false;
        if (!uploads.some((u) => u.state === 'uploading' || u.state === 'queued')) markIdle(`images:${props.productId}`);
    }
}

function retry(u: Upload) {
    u.state = 'queued';
    u.progress = 0;
    u.error = undefined;
    void drain();
}
function cancel(u: Upload) {
    if (u.state === 'uploading') u.ctrl?.abort();
    else uploads.splice(uploads.indexOf(u), 1);
}

// ----------------------------------------------------------------- poll
let timer: ReturnType<typeof setTimeout> | null = null;
function startPolling() {
    if (timer) return;
    const tick = async () => {
        timer = null;
        if (!items.value.some((i) => i.status === 'processing')) return;
        try {
            apply(await api.list());
        } catch {
            /* offline: try again next tick */
        }
        timer = setTimeout(tick, 1500);
    };
    timer = setTimeout(tick, 1000);
}
watch(() => items.value.some((i) => i.status === 'processing'), (p) => p && startPolling(), { immediate: true });
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
    markIdle(`images:${props.productId}`);
});

// --------------------------------------------------------------- mutate
async function run(fn: () => Promise<ImagePayload>): Promise<boolean> {
    if (blocked.value) return false;
    busy.value = true;
    message.value = null;
    try {
        apply(await fn());
        return true;
    } catch (e: any) {
        if (e instanceof ConflictError) {
            message.value = t('images.conflict'); // IMG-11
            try { apply(await api.list()); } catch { /* ignore */ }
        } else {
            message.value = e.message;
            if (e.network) void conn.reportFailure();
        }
        return false;
    } finally {
        busy.value = false;
    }
}

const ids = computed(() => items.value.map((i) => i.id));

function move(id: number, delta: -1 | 1) {
    const order = moveId(ids.value, id, delta);
    if (order === ids.value) return;
    const before = items.value;
    items.value = order.map((x) => before.find((i) => i.id === x)!); // optimistic
    void run(() => api.reorder(order, etag.value)).then((ok) => !ok && !message.value && (items.value = before));
}

const dragId = ref<number | null>(null);
function onDrop(targetId: number) {
    if (dragId.value === null) return;
    const order = dropBefore(ids.value, dragId.value, targetId);
    dragId.value = null;
    if (order.join() === ids.value.join()) return;
    items.value = order.map((x) => items.value.find((i) => i.id === x)!);
    void run(() => api.reorder(order, etag.value));
}

const makePrimary = (i: ImageItem) => run(() => api.update(i.id, { is_primary: true, etag: etag.value }));

async function removeSelected() {
    if (!selected.value.size || !window.confirm(t('images.confirmDelete'))) return;
    if (await run(() => api.remove([...selected.value]))) {
        selected.value = new Set();
        selecting.value = false;
    }
}
async function removeOne(i: ImageItem) {
    if (!window.confirm(t('images.confirmDelete'))) return;
    if (await run(() => api.remove([i.id]))) editing.value = null;
}
function toggle(id: number) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}

// ---------------------------------------------------------------- editor
const form = reactive({ alt_text: '', caption: '', variant_ids: [] as number[] });
function openEditor(i: ImageItem) {
    editing.value = i;
    form.alt_text = i.alt_text ?? '';
    form.caption = i.caption ?? '';
    form.variant_ids = [...i.variant_ids];
}
async function saveEditor() {
    if (!editing.value) return;
    const id = editing.value.id;
    if (await run(() => api.update(id, { alt_text: form.alt_text || null, caption: form.caption || null, variant_ids: form.variant_ids }))) editing.value = null;
}
async function transform(t_: { rotate?: number; crop?: string }) {
    if (!editing.value) return;
    const id = editing.value.id;
    if (await run(() => api.update(id, { transform: t_ }))) startPolling();
}
async function replaceFile(files: FileList | null) {
    if (!files?.[0] || !editing.value) return;
    const id = editing.value.id;
    const f = await downscale(files[0]);
    if (await run(() => api.replace(id, f))) startPolling();
}
const variantLabel = (v: { sku: string; options: Record<string, string> }) => `${v.sku} (${Object.values(v.options).join(' / ')})`;
</script>

<template>
    <section aria-labelledby="img-h">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 id="img-h" class="text-base font-semibold">{{ t('products.images') }}</h2>
                <p class="text-xs text-muted" data-testid="image-count">{{ t('images.limit', { count: items.length, max }) }}</p>
            </div>
            <div v-if="canManage" class="flex flex-wrap gap-2">
                <button v-if="items.length" type="button" class="btn-secondary" @click="selecting = !selecting; selected = new Set()">
                    {{ selecting ? t('images.done') : t('images.select') }}
                </button>
                <button v-if="selecting && selected.size" type="button" class="btn-secondary !text-danger" :disabled="blocked" @click="removeSelected">
                    {{ t('images.deleteSelected', { count: selected.size }) }}
                </button>
            </div>
        </div>

        <p v-if="message" role="alert" class="mt-2 rounded-lg bg-danger/15 px-3 py-2 text-sm" data-testid="image-message">{{ message }}</p>
        <p v-for="(w, n) in warnings" :key="n" class="mt-2 rounded-lg bg-warn/15 px-3 py-2 text-sm">{{ w }}</p>
        <p v-if="canManage && conn.writesBlocked.value" class="mt-2 text-xs text-warn">{{ t('images.offline') }}</p>

        <!-- Add: file picker, camera, drag-and-drop (desktop) -->
        <div
            v-if="canManage"
            class="mt-3 rounded-xl border-2 border-dashed border-line p-4 text-center"
            @dragover.prevent
            @drop.prevent="pick($event.dataTransfer?.files ?? null)"
        >
            <p class="mb-3 hidden text-sm text-muted sm:block">{{ t('images.dropHint') }}</p>
            <div class="flex flex-wrap justify-center gap-2">
                <label class="btn-primary cursor-pointer" :class="{ 'pointer-events-none opacity-50': blocked || items.length + uploads.length >= max }">
                    {{ t('images.add') }}
                    <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif" data-testid="image-input" :disabled="blocked" @change="pick(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />
                </label>
                <label class="btn-secondary cursor-pointer sm:hidden" :class="{ 'pointer-events-none opacity-50': blocked }">
                    {{ t('images.camera') }}
                    <input type="file" class="sr-only" accept="image/*" capture="environment" :disabled="blocked" @change="pick(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />
                </label>
            </div>
        </div>

        <!-- In-flight uploads: progress, cancel, retry -->
        <ul v-if="uploads.length" class="mt-3 space-y-2" aria-live="polite">
            <li v-for="u in uploads" :key="u.key" class="rounded-lg border border-line bg-card p-3 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ u.name }}</span>
                    <span class="flex shrink-0 gap-2">
                        <button v-if="u.state === 'failed'" type="button" class="btn-secondary !min-h-[36px] !px-3" :disabled="conn.writesBlocked.value" @click="retry(u)">{{ t('images.retry') }}</button>
                        <button type="button" class="btn-secondary !min-h-[36px] !px-3" @click="cancel(u)">{{ t('images.cancel') }}</button>
                    </span>
                </div>
                <progress v-if="u.state !== 'failed'" class="mt-2 h-2 w-full" max="100" :value="u.progress" :aria-label="u.name"></progress>
                <p v-else class="mt-1 text-danger">{{ u.error || t('images.uploadFailed') }}</p>
            </li>
        </ul>

        <p v-if="!items.length && !uploads.length" class="mt-3 text-sm text-muted">{{ t('images.empty') }}</p>

        <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-testid="image-grid">
            <li
                v-for="(i, idx) in items"
                :key="i.id"
                class="overflow-hidden rounded-xl border border-line bg-card"
                :class="{ 'ring-2 ring-brand': selected.has(i.id) }"
                :draggable="canManage && !blocked && !selecting"
                :data-image-id="i.id"
                @dragstart="dragId = i.id"
                @dragover.prevent
                @drop.prevent="onDrop(i.id)"
            >
                <div class="relative aspect-square bg-surface">
                    <img v-if="i.card" :src="i.card" :alt="i.alt_text ?? ''" class="h-full w-full object-cover" loading="lazy" width="400" height="400" />
                    <div v-else class="grid h-full place-items-center p-2 text-center text-xs">
                        <StatusBadge :tone="i.status === 'failed' ? 'danger' : 'warn'">{{ t(`images.${i.status}`) }}</StatusBadge>
                        <span v-if="i.error" class="mt-1 text-danger">{{ i.error }}</span>
                    </div>
                    <span v-if="i.is_primary" class="absolute left-2 top-2"><StatusBadge tone="ok">{{ t('images.primary') }}</StatusBadge></span>
                    <label v-if="selecting" class="absolute right-2 top-2 grid h-11 w-11 place-items-center rounded-full bg-card/90">
                        <input type="checkbox" class="h-5 w-5" :checked="selected.has(i.id)" :aria-label="t('images.select')" @change="toggle(i.id)" />
                    </label>
                </div>
                <div v-if="canManage" class="flex items-center justify-between gap-1 p-1.5">
                    <div class="flex">
                        <button type="button" class="btn-secondary !min-h-[44px] !min-w-[44px] !px-0" :disabled="blocked || idx === 0" :aria-label="t('images.moveUp')" data-action="up" @click="move(i.id, -1)">←</button>
                        <button type="button" class="btn-secondary ml-1 !min-h-[44px] !min-w-[44px] !px-0" :disabled="blocked || idx === items.length - 1" :aria-label="t('images.moveDown')" data-action="down" @click="move(i.id, 1)">→</button>
                    </div>
                    <button type="button" class="btn-secondary !px-3" data-action="edit" @click="openEditor(i)">{{ t('common.edit') }}</button>
                </div>
            </li>
        </ul>

        <Sheet :open="!!editing" :title="t('images.edit')" @close="editing = null">
            <div v-if="editing" class="space-y-4">
                <img v-if="editing.detail" :src="editing.detail" :alt="editing.alt_text ?? ''" class="mx-auto max-h-64 rounded-lg object-contain" />
                <div>
                    <label class="label" for="img-alt">{{ t('images.alt') }}</label>
                    <input id="img-alt" v-model="form.alt_text" class="field" maxlength="200" />
                </div>
                <div>
                    <label class="label" for="img-cap">{{ t('images.caption') }}</label>
                    <input id="img-cap" v-model="form.caption" class="field" maxlength="300" />
                </div>
                <fieldset v-if="variants.length">
                    <legend class="label">{{ t('images.variants') }}</legend>
                    <label v-for="v in variants" :key="v.id" class="flex touch-target items-center gap-2 text-sm">
                        <input v-model="form.variant_ids" type="checkbox" class="h-5 w-5" :value="v.id" /> {{ variantLabel(v) }}
                    </label>
                </fieldset>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-secondary" :disabled="blocked || editing.status !== 'ready'" data-action="rotate" @click="transform({ rotate: 90 })">{{ t('images.rotate') }}</button>
                    <button type="button" class="btn-secondary" :disabled="blocked || editing.status !== 'ready'" @click="transform({ crop: 'square' })">{{ t('images.cropSquare') }}</button>
                    <button type="button" class="btn-secondary" :disabled="blocked || editing.status !== 'ready'" @click="transform({ crop: '4:3' })">{{ t('images.crop43') }}</button>
                    <button v-if="!editing.is_primary" type="button" class="btn-secondary" :disabled="blocked" data-action="primary" @click="makePrimary(editing)">{{ t('images.makePrimary') }}</button>
                    <label class="btn-secondary cursor-pointer" :class="{ 'pointer-events-none opacity-50': blocked }">
                        {{ t('images.replace') }}
                        <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" data-testid="replace-input" :disabled="blocked" @change="replaceFile(($event.target as HTMLInputElement).files)" />
                    </label>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-line pt-4">
                    <button type="button" class="btn-secondary !text-danger" :disabled="blocked" data-action="delete" @click="removeOne(editing)">{{ t('images.delete') }}</button>
                    <button type="button" class="btn-primary" :disabled="blocked || busy" data-action="save" @click="saveEditor">{{ t('common.save') }}</button>
                </div>
            </div>
        </Sheet>
    </section>
</template>
