import { describe, expect, it } from 'vitest';
import { estimate, fromPaisa, toPaisa } from './money';
import { bdPhone } from './phone';

describe('estimate (mirrors the server formula)', () => {
    it('matches the spec example: 2000 + 80 delivery, 100 advance', () => {
        const e = estimate([{ qty: 1, unit_price: '2000', discount: '0' }], { shipping_charge: '80', advance: '100' });
        expect(e.total).toBe('2080.00');
        expect(e.cod).toBe('1980.00');
    });
    it('applies line and order discounts, other charges and tax', () => {
        const e = estimate([{ qty: 3, unit_price: '100.50', discount: '10' }], { order_discount: '5', other_charges: '20', tax_amount: '2.25' });
        expect(e.subtotal).toBe('301.50');
        expect(e.total).toBe('308.75'); // 301.50 - 10 - 5 + 20 + 2.25
    });
    it('has no float drift and flags impossible inputs', () => {
        expect(toPaisa('0.1') + toPaisa('0.2')).toBe(30);
        expect(fromPaisa(30)).toBe('0.30');
        expect(estimate([{ qty: 1, unit_price: '10' }], { order_discount: '50' }).negative).toBe(true);
        expect(estimate([{ qty: 1, unit_price: '10' }], { advance: '50' }).advanceTooHigh).toBe(true);
    });
});

describe('bdPhone', () => {
    it('normalises +880 / 880 / 10-digit forms', () => {
        expect(bdPhone('+880 1712-345678')).toBe('01712345678');
        expect(bdPhone('8801712345678')).toBe('01712345678');
        expect(bdPhone('1712345678')).toBe('01712345678');
    });
});
