// Skenario kasir dari audit 21 Oktober 2026: deposit tanpa member, provider tertinggal di tunai,
// dan diskon persen desimal. Usage: WARUNG_BASE_URL=http://127.0.0.1:8765 node tests/browser/pos-payment-audit.cjs
// Jalankan HANYA terhadap schema audit yang di-seed demo.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base = process.env.WARUNG_BASE_URL || 'http://127.0.0.1:8765';
const failures = [];
const check = (ok, label, detail = '') => { console.log((ok ? 'ok   ' : 'FAIL ') + label + (detail ? ' :: ' + detail : '')); if (!ok) failures.push(label); };

(async () => {
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    const p = await (await browser.newContext({viewport: {width: 1366, height: 900}})).newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('dialog', (d) => { errors.push('dialog: ' + d.message()); d.accept(); });
    await p.goto(base + '/login');
    await p.fill('[name=email]', 'kasir.melati@warungkita.id');
    await p.fill('[name=password]', 'password');
    await Promise.all([p.waitForURL((u) => !u.pathname.startsWith('/login')), p.click('button.btn-primary')]);

    const fresh = async () => {
        await p.goto(base + '/kasir');
        await p.click('[data-service="takeaway"]').catch(() => {});
        await p.locator('.product-card:not([disabled])').first().click();
        if (await p.locator('#price-picker-modal.open .price-choice').count()) await p.locator('#price-picker-modal .price-choice').first().click();
    };
    const pay = async () => {
        if (await p.locator('#table-number').isVisible()) await p.fill('#table-number', 'A1');
        const response = p.waitForResponse((r) => r.url().includes('/kasir/checkout'), {timeout: 15000}).catch(() => null);
        await p.click('#checkout-btn');
        const r = await response;
        await p.waitForTimeout(800);
        for (const pg of p.context().pages()) if (pg !== p) await pg.close();
        return r ? {status: r.status(), body: await r.json().catch(() => ({})), request: JSON.parse(r.request().postData() || '{}')} : {status: 0, body: {}, request: {}};
    };

    // 1. Deposit diklik tanpa member, lalu kembali ke Tunai.
    await fresh();
    await p.click('.payment[data-payment="deposit"]');
    await p.click('.payment[data-payment="cash"]');
    check(await p.locator('#paid-field').isVisible(), 'kolom uang diterima tetap tampil setelah Deposit -> Tunai tanpa member');
    await p.fill('#paid-amount', '500000');
    let r = await pay();
    check(r.status === 200, 'checkout tunai setelah klik Deposit tanpa member', r.status + ' ' + JSON.stringify(r.body).slice(0, 120));
    check(!(r.request.payments || []).some((x) => x.method === 'deposit'), 'payload tanpa pembayaran deposit');

    // 2. QRIS + provider lalu kembali ke Tunai.
    await fresh();
    await p.click('.payment[data-payment="qris"]');
    await p.locator('#payment-provider').selectOption({index: 1});
    await p.click('.payment[data-payment="cash"]');
    await p.fill('#paid-amount', '500000');
    r = await pay();
    check(r.status === 200, 'checkout tunai setelah memilih QRIS', String(r.status));
    check((r.request.payments || []).every((x) => x.provider === null), 'provider tidak terkirim untuk tunai', JSON.stringify(r.request.payments));

    // 3. Diskon persen desimal.
    await fresh();
    await p.selectOption('#discount-type', 'percent');
    await p.fill('#discount', '12,5');
    await p.locator('#discount').dispatchEvent('input');
    check(await p.inputValue('#discount') === '12,5', 'input diskon % tidak diformat sebagai rupiah', await p.inputValue('#discount'));
    await p.fill('#paid-amount', '500000');
    r = await pay();
    check(r.status === 200 && Number(r.request.discount_value) === 12.5, 'checkout diskon 12,5%', r.status + ' value=' + r.request.discount_value);
    await p.selectOption('#discount-type', 'amount').catch(() => {});

    // 4. Diskon Rp tetap berformat ribuan.
    await fresh();
    await p.fill('#discount', '1500');
    await p.locator('#discount').dispatchEvent('input');
    check(await p.inputValue('#discount') === '1.500', 'diskon Rp tetap berformat ribuan', await p.inputValue('#discount'));

    check(errors.length === 0, 'tanpa error JS / alert', errors.join(' | '));
    await browser.close();
    process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
