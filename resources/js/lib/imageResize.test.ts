import { describe, expect, it } from 'vitest';
import { downscale, targetSize } from './imageResize';

describe('imageResize', () => {
    it('keeps images already within the limit', () => {
        expect(targetSize(1200, 800)).toEqual({ w: 1200, h: 800, scaled: false });
    });
    it('scales the long edge to 2000 keeping the aspect ratio', () => {
        expect(targetSize(4000, 3000)).toEqual({ w: 2000, h: 1500, scaled: true });
        expect(targetSize(3000, 6000)).toEqual({ w: 1000, h: 2000, scaled: true });
    });
    it('passes through types it cannot decode (HEIC, GIF) untouched', async () => {
        const heic = new File(['x'], 'a.heic', { type: 'image/heic' });
        expect(await downscale(heic)).toBe(heic);
    });
});
