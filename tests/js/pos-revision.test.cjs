const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/pos/index.blade.php'), 'utf8');
const line = (name) => source.match(new RegExp(`^function ${name}\\(.*$`, 'm'))[0];
const start = source.indexOf('async function submitOrder(');
const submitFunction = source.slice(start, source.indexOf('\n}', start) + 2);

function totalsContext({ subtotal, serviceType, discountType = 'amount', discount = '0', replacement = false, rounding = {} }) {
    const elements = {
        'discount-type': { value: discountType }, discount: { value: discount },
        'member-id': { selectedOptions: [{ dataset: { discount: '10' } }] }, 'replacement-mode': { checked: replacement },
    };
    const context = vm.createContext({
        serviceType,
        cart: new Map([['p1', { price: subtotal, qty: 1 }]]),
        chargeConfig: { service_percent: 5, service_types: ['dine_in'], tax_percent: 10, tax_types: ['dine_in', 'takeaway', 'online'], tax_label: 'PB1', ...rounding },
        moneyValue: (input) => Number(String(input.value).replace(/\D/g, '') || 0),
        document: { getElementById: (id) => elements[id] },
    });
    vm.runInContext(line('totals'), context);
    return context.totals();
}

test('cashier totals match Store::chargesFor for service and tax', () => {
    const dineIn = totalsContext({ subtotal: 100000, serviceType: 'dine_in' });
    assert.equal(dineIn.service, 5000);
    assert.equal(dineIn.tax, 10500);
    assert.equal(dineIn.total, 115500);

    const takeaway = totalsContext({ subtotal: 100000, serviceType: 'takeaway', discount: '10.000' });
    assert.equal(takeaway.service, 0);
    assert.equal(takeaway.tax, 9000);
    assert.equal(takeaway.total, 99000);

    assert.equal(totalsContext({ subtotal: 100000, serviceType: 'dine_in', replacement: true }).total, 100000);
});

test('cashier rounding matches Store::roundTotal', () => {
    // 20.000 + PB1 10% = 22.000; 20.200 + 2.020 = 22.220.
    const run = (subtotal, mode, unit) => totalsContext({ subtotal, serviceType: 'takeaway', rounding: { rounding_mode: mode, rounding_unit: unit } });
    assert.equal(run(20200, 'none', 500).total, 22220);
    assert.equal(run(20200, 'none', 500).rounding, 0);
    const up = run(20200, 'up', 500);
    assert.equal(up.total, 22500);
    assert.equal(up.rounding, 280);
    assert.equal(run(20000, 'up', 500).rounding, 0);
    const down = run(20200, 'down', 1000);
    assert.equal(down.total, 22000);
    assert.equal(down.rounding, -220);
    assert.equal(run(20200, 'nearest', 500).total, 22000);
    assert.equal(run(20500, 'nearest', 100).total, 22600);
    // Pembulatan ke bawah tidak membuat tagihan menjadi nol; keranjang kosong tetap nol.
    assert.equal(run(200, 'down', 1000).total, 220);
    assert.equal(run(0, 'up', 1000).total, 0);
    // Konfigurasi lama tanpa pembulatan tetap berjalan.
    assert.equal(totalsContext({ subtotal: 20200, serviceType: 'takeaway' }).total, 22220);
});

function cartContext() {
    const products = [{ id: 1, name: 'Es Teh', price: 6000, online_price: 7000, stock: 5, increment: 1, prices: [{ id: 9, label: 'Jumbo', price: 9000, online_price: 11000 }] }];
    const context = vm.createContext({ products, cart: new Map(), serviceType: 'takeaway', renderCart: () => {} });
    context.byId = (id) => products.find((product) => product.id === Number(id));
    ['priceOf', 'qtyInCart', 'maxQty', 'addItem'].forEach((name) => vm.runInContext(line(name), context));
    return context;
}

test('price options become separate cart lines that share one stock limit', () => {
    const context = cartContext();
    for (let i = 0; i < 3; i++) context.addItem(1, null);
    for (let i = 0; i < 4; i++) context.addItem(1, 9);
    const lines = [...context.cart.values()];
    assert.deepEqual(lines.map((item) => [item.key, item.name, item.price, item.qty]), [
        ['p1', 'Es Teh', 6000, 3],
        ['p1-9', 'Es Teh (Jumbo)', 9000, 2],
    ]);
    context.serviceType = 'online';
    assert.equal(context.priceOf(context.byId(1), 9), 11000);
    assert.equal(context.priceOf(context.byId(1), null), 7000);
});

test('Epson printing replaces the browser popup and falls back when it fails', async () => {
    for (const mode of ['printed', 'failed']) {
        const calls = [];
        const context = vm.createContext({
            URL, cart: new Map([['1', {}]]), payment: 'cash', serviceType: 'takeaway',
            orderPayload: () => ({ payments: [] }), totals: () => ({ total: 0 }),
            document: { getElementById: () => ({ disabled: false }), querySelector: () => ({ content: 'token' }) },
            window: {
                open: () => { calls.push(['open']); return null; },
                PosPrinter: {
                    isReady: () => true,
                    printJobs: async (payload) => { calls.push(['epos', payload.jobs.length]); if (mode === 'failed') throw new Error('Kertas struk habis.'); },
                },
            },
            location: { origin: 'https://warung.test', assign: (url) => calls.push(['fallback', url]), reload: () => calls.push(['reload']) },
            alert: (message) => calls.push(['alert', message]),
            fetch: async () => { calls.push(['save']); return { ok: true, json: async () => ({ invoice: 'TRX-1', print_url: '/transaksi/1/print', epos: { jobs: [[], []] } }) }; },
        });
        vm.runInContext(submitFunction, context);
        await context.submitOrder('/checkout');
        if (mode === 'printed') {
            assert.deepEqual(calls, [['save'], ['epos', 2], ['reload']]);
        } else {
            assert.deepEqual(calls.map((call) => call[0]), ['save', 'epos', 'alert', 'fallback']);
            assert.match(calls[2][1], /TRX-1 tersimpan/);
            assert.match(calls[3][1], /autoprint=1/);
        }
    }
});
