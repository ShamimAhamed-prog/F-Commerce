/** Estimate-only decimal maths in integer paisa (no floats). The server computes the authoritative total. */
export const toPaisa = (v: string | number | null | undefined): number => {
    const s = String(v ?? '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d{0,2})?$/.test(s)) return 0;
    const [i, f = ''] = s.split('.');
    return Number(i) * 100 + Number(f.padEnd(2, '0'));
};
export const fromPaisa = (p: number): string => `${Math.trunc(p / 100)}.${String(Math.abs(p % 100)).padStart(2, '0')}`;

export interface EstLine { qty: number | string; unit_price: string | number; discount?: string | number }
export function estimate(lines: EstLine[], o: { order_discount?: string; shipping_charge?: string; other_charges?: string; tax_amount?: string; advance?: string }) {
    const sub = lines.reduce((a, l) => a + Number(l.qty || 0) * toPaisa(l.unit_price), 0);
    const disc = lines.reduce((a, l) => a + toPaisa(l.discount), 0);
    const total = sub - disc - toPaisa(o.order_discount) + toPaisa(o.shipping_charge) + toPaisa(o.other_charges) + toPaisa(o.tax_amount);
    return { subtotal: fromPaisa(sub), total: fromPaisa(Math.max(0, total)), cod: fromPaisa(Math.max(0, total - toPaisa(o.advance))), negative: total < 0, advanceTooHigh: toPaisa(o.advance) > total };
}
