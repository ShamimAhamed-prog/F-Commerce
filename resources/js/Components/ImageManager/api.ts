import axios, { type AxiosProgressEvent } from 'axios';

export interface ImageItem {
    id: number;
    position: number;
    is_primary: boolean;
    alt_text: string | null;
    caption: string | null;
    status: 'processing' | 'ready' | 'failed';
    error: string | null;
    variant_ids: number[];
    thumb: string | null;
    card: string | null;
    detail: string | null;
}

export interface ImagePayload {
    etag: string;
    max: number;
    items: ImageItem[];
    warnings?: string[];
}

export class ConflictError extends Error {}

const http = axios.create({ headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, withCredentials: true });

function wrap<T>(p: Promise<{ data: T }>): Promise<T> {
    return p.then((r) => r.data).catch((e) => {
        if (axios.isCancel(e)) throw e;
        if (e.response?.status === 409) throw new ConflictError(e.response.data?.message ?? 'conflict');
        const msg = e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : e.response?.data?.message;
        const err = new Error(msg || 'Request failed');
        (err as any).network = !e.response;
        throw err;
    });
}

export const imagesApi = (productId: number) => {
    const base = `/app/products/${productId}/images`;
    return {
        list: () => wrap<ImagePayload>(http.get(base)),
        upload: (file: File, onProgress: (pct: number) => void, signal: AbortSignal) => {
            const fd = new FormData();
            fd.append('images[]', file);
            return wrap<ImagePayload>(
                http.post(base, fd, {
                    signal,
                    onUploadProgress: (e: AxiosProgressEvent) => e.total && onProgress(Math.round((e.loaded / e.total) * 100)),
                }),
            );
        },
        replace: (id: number, file: File, signal?: AbortSignal) => {
            const fd = new FormData();
            fd.append('image', file);
            return wrap<ImagePayload>(http.post(`${base}/${id}/replace`, fd, { signal }));
        },
        update: (id: number, data: Record<string, unknown>) => wrap<ImagePayload>(http.patch(`${base}/${id}`, data)),
        reorder: (order: number[], etag: string) => wrap<ImagePayload>(http.post(`${base}/reorder`, { order, etag })),
        remove: (ids: number[]) => wrap<ImagePayload>(http.delete(base, { data: { ids } })),
    };
};

/** Pure helper (unit-tested): move one id by `delta` places within an order. */
export function moveId(order: number[], id: number, delta: -1 | 1): number[] {
    const i = order.indexOf(id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= order.length) return order;
    const next = order.slice();
    [next[i], next[j]] = [next[j], next[i]];
    return next;
}

/** Pure helper: drop `id` at the position of `targetId` (desktop drag-and-drop). */
export function dropBefore(order: number[], id: number, targetId: number): number[] {
    if (id === targetId) return order;
    const next = order.filter((x) => x !== id);
    next.splice(next.indexOf(targetId), 0, id);
    return next;
}
