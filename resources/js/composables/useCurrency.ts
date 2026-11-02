/**
 * Money display (NFR-7). Display only: amounts are DECIMAL strings from the
 * server and are never used for authoritative client-side math.
 */
let LAKH = false;
/** Workspace preference (NFR-7): set once from shared props. */
export const setLakh = (v: boolean) => (LAKH = v);

export function formatBdt(amount: string | number, opts: { lakh?: boolean } = {}): string {
    const [intPart, frac = '00'] = String(amount).replace(/^-/, '').split('.');
    const neg = String(amount).startsWith('-') ? '-' : '';
    const grouped = (opts.lakh ?? LAKH) ? groupLakh(intPart) : intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${neg}৳${grouped}.${frac.padEnd(2, '0').slice(0, 2)}`;
}

function groupLakh(int: string): string {
    if (int.length <= 3) return int;
    const last3 = int.slice(-3);
    const rest = int.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    return `${rest},${last3}`;
}
