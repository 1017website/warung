const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/pos/index.blade.php'), 'utf8');
const start = source.indexOf('async function submitOrder(');
const checkout = source.slice(start, source.indexOf('\n}', start) + 2);

for (const mode of ['popup', 'blocked', 'failed', 'hold']) {
    test(`checkout printing: ${mode}`, async () => {
        const calls = [];
        const button = { disabled: false };
        const popup = {
            document: { body: {} }, closed: false,
            location: { replace: url => calls.push(['receipt', url]) },
            close: () => calls.push(['close'])
        };
        const context = vm.createContext({
            URL, cart: new Map([['1', {}]]), payment: 'cash', serviceType: 'takeaway',
            orderPayload: () => ({ payments: [] }),
            document: { getElementById: () => button, querySelector: () => ({ content: 'token' }) },
            window: { open: () => { calls.push(['open']); return mode === 'blocked' ? null : popup; } },
            location: { origin: 'https://warung.test', assign: url => calls.push(['fallback', url]), reload: () => calls.push(['reload']) },
            alert: message => calls.push(['alert', message]),
            fetch: async () => {
                calls.push(['save']);
                return { ok: mode !== 'failed', json: async () => mode === 'hold' ? { invoice: 'TRX-1' } : { print_url: '/transaksi/1/print', message: 'Gagal' } };
            }
        });
        vm.runInContext(checkout, context);
        await context.submitOrder('/checkout', mode === 'hold');
        assert.equal(button.disabled, false);
        if (mode === 'popup') {
            assert.deepEqual(calls.map(call => call[0]), ['open', 'save', 'receipt', 'reload']);
            assert.match(calls[2][1], /autoprint=1/);
        } else if (mode === 'blocked') {
            assert.deepEqual(calls.map(call => call[0]), ['open', 'save', 'fallback']);
            assert.match(calls[2][1], /autoprint=1/);
        } else if (mode === 'failed') {
            assert.deepEqual(calls.map(call => call[0]), ['open', 'save', 'close', 'alert']);
        } else {
            assert.deepEqual(calls.map(call => call[0]), ['save', 'alert', 'reload']);
        }
    });
}
