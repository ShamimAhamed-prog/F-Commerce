import { describe, expect, it } from 'vitest';
import { dropBefore, moveId } from './api';

describe('image reorder helpers', () => {
    it('moves an image earlier/later by one', () => {
        expect(moveId([1, 2, 3], 2, -1)).toEqual([2, 1, 3]);
        expect(moveId([1, 2, 3], 2, 1)).toEqual([1, 3, 2]);
    });
    it('leaves the order alone at the edges and for unknown ids (same reference)', () => {
        const o = [1, 2, 3];
        expect(moveId(o, 1, -1)).toBe(o);
        expect(moveId(o, 3, 1)).toBe(o);
        expect(moveId(o, 9, 1)).toBe(o);
    });
    it('does not mutate its input', () => {
        const o = [1, 2, 3];
        moveId(o, 2, 1);
        expect(o).toEqual([1, 2, 3]);
    });
    it('drops a dragged image before the target (desktop drag-and-drop)', () => {
        expect(dropBefore([1, 2, 3, 4], 4, 2)).toEqual([1, 4, 2, 3]);
        expect(dropBefore([1, 2, 3, 4], 1, 3)).toEqual([2, 1, 3, 4]);
        expect(dropBefore([1, 2, 3], 2, 2)).toEqual([1, 2, 3]);
    });
});
