/**
 * Client-side downscale before upload (spec §5.3A): long edge <= 2000 px, JPEG ~0.85,
 * to save mobile data. Best-effort: anything the browser cannot decode (e.g. HEIC) is sent
 * unchanged and the server validates and converts independently.
 */
export const MAX_EDGE = 2000;

export function targetSize(w: number, h: number, max = MAX_EDGE): { w: number; h: number; scaled: boolean } {
    const long = Math.max(w, h);
    if (long <= max) return { w, h, scaled: false };
    const r = max / long;
    return { w: Math.round(w * r), h: Math.round(h * r), scaled: true };
}

export async function downscale(file: File, max = MAX_EDGE): Promise<File> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap === 'undefined') return file;
    try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const t = targetSize(bmp.width, bmp.height, max);
        if (!t.scaled && file.size < 1_500_000) {
            bmp.close();
            return file;
        }
        const canvas = document.createElement('canvas');
        canvas.width = t.w;
        canvas.height = t.h;
        canvas.getContext('2d')!.drawImage(bmp, 0, 0, t.w, t.h);
        bmp.close();
        const blob: Blob | null = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.85));
        if (!blob || blob.size >= file.size) return file;
        return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch {
        return file;
    }
}
