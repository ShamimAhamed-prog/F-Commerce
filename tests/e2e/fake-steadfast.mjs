// Minimal stand-in for the Steadfast merchant API, used ONLY by browser tests (no real parcels are ever booked).
// Mirrors the documented behaviour: Api-Key/Secret-Key headers, duplicate invoices refused, 401 "Unauthorized Access"
// for bad keys AND for unknown parcels, status by invoice.
import http from 'node:http';

const parcels = new Map();
let seq = 1000;
const send = (res, code, body) => {
    res.writeHead(code, { 'Content-Type': typeof body === 'string' ? 'text/plain' : 'application/json' });
    res.end(typeof body === 'string' ? body : JSON.stringify(body));
};

http.createServer((req, res) => {
    const url = new URL(req.url, 'http://x');
    const path = url.pathname.replace('/api/v1', '');
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
        if (path === '/ping') return send(res, 200, { status: 200, message: 'pong' });
        if (path === '/__reset') { parcels.clear(); return send(res, 200, { ok: true }); }
        // test control: POST /__set/:invoice/:status
        if (path.startsWith('/__set/')) { const [, , inv, st] = path.split('/'); if (parcels.has(inv)) parcels.get(inv).status = st; return send(res, 200, { ok: true }); }
        if (req.headers['api-key'] !== 'e2e-key' || req.headers['secret-key'] !== 'e2e-secret') return send(res, 401, 'Unauthorized Access');

        if (path === '/get_balance') return send(res, 200, { status: 200, current_balance: 0 });
        if (path === '/create_order' && req.method === 'POST') {
            const b = JSON.parse(raw || '{}');
            if (!b.invoice || !b.recipient_name || !b.recipient_phone || !b.recipient_address || b.cod_amount === undefined) return send(res, 422, { status: 422, message: 'validation', errors: { recipient_address: ['The recipient address field is required.'] } });
            if (parcels.has(b.invoice)) return send(res, 422, { status: 422, message: 'validation', errors: { invoice: ['The invoice has already been taken.'] } });
            const id = ++seq;
            parcels.set(b.invoice, { id, status: 'in_review' });
            return send(res, 200, { status: 200, message: 'Consignment has been created successfully.', consignment: { consignment_id: id, invoice: b.invoice, tracking_code: `TRK${id}`, status: 'in_review' } });
        }
        if (path.startsWith('/status_by_invoice/')) {
            const p = parcels.get(decodeURIComponent(path.split('/')[2]));
            return p ? send(res, 200, { status: 200, delivery_status: p.status }) : send(res, 401, 'Unauthorized Access');
        }
        send(res, 404, 'Not Found');
    });
}).listen(8130, '127.0.0.1');
