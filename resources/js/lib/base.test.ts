import { describe, expect, it } from 'vitest';
import { stripBase, withBase, withBaseUrl } from './base';

describe('sub-folder base path', () => {
    it('prefixes root-relative paths once (idempotent)', () => {
        expect(withBase('/app/orders', '/fcom-manager')).toBe('/fcom-manager/app/orders');
        expect(withBase('/fcom-manager/app/orders', '/fcom-manager')).toBe('/fcom-manager/app/orders');
        expect(withBase('/app/orders?x=1', '/fcom-manager')).toBe('/fcom-manager/app/orders?x=1');
    });
    it('is a no-op at the domain root and for non-path values', () => {
        expect(withBase('/app/orders', '')).toBe('/app/orders');
        for (const v of ['tel:+880', 'https://x.test/a', '//cdn.test/a', '#top', 'relative/path']) expect(withBase(v, '/fcom-manager')).toBe(v);
    });
    it('does not treat a look-alike folder as already prefixed', () => {
        expect(withBase('/fcom-manager-old/x', '/fcom-manager')).toBe('/fcom-manager/fcom-manager-old/x');
    });
    it('prefixes same-origin full URLs only', () => {
        const o = window.location.origin;
        expect(withBaseUrl(`${o}/app/orders`, '/fcom-manager')).toBe(`${o}/fcom-manager/app/orders`);
        expect(withBaseUrl('https://other.test/app/orders', '/fcom-manager')).toBe('https://other.test/app/orders');
    });
    it('strips the base for nav matching', () => {
        expect(stripBase('/fcom-manager/app/orders?p=2', '/fcom-manager')).toBe('/app/orders?p=2');
        expect(stripBase('/app/orders', '/fcom-manager')).toBe('/app/orders');
        expect(stripBase('/fcom-manager', '/fcom-manager')).toBe('/');
    });
});
