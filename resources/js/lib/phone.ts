/** Digits-only normalisation to 01XXXXXXXXX for tel:/wa.me links (display only; the server is authoritative). */
export function bdPhone(raw: string): string {
    let d = (raw || '').replace(/\D+/g, '');
    if (d.startsWith('8801')) d = d.slice(2);
    else if (/^1\d{9}$/.test(d)) d = '0' + d;
    return d;
}
export const telHref = (raw: string) => `tel:+88${bdPhone(raw)}`;
export const waHref = (raw: string) => `https://wa.me/88${bdPhone(raw)}`;
