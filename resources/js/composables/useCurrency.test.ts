import { describe, expect, it } from 'vitest';
import { formatBdt } from './useCurrency';

describe('formatBdt', () => {
    it('uses western grouping by default', () => {
        expect(formatBdt('1234567.5')).toBe('৳1,234,567.50');
    });
    it('supports lakh/crore grouping', () => {
        expect(formatBdt('100000')).toBe('৳100,000.00');
        expect(formatBdt('100000', { lakh: true })).toBe('৳1,00,000.00');
        expect(formatBdt('12345678.9', { lakh: true })).toBe('৳1,23,45,678.90');
        expect(formatBdt('999', { lakh: true })).toBe('৳999.00');
    });
    it('keeps the sign and never floats the decimals', () => {
        expect(formatBdt('-2080.1')).toBe('-৳2,080.10');
    });
});
