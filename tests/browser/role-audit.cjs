// Audit seluruh modul per role: buka setiap menu, cek setiap link, kirim setiap jenis form,
// dan jalankan alur JS (kasir, live edit stok). Semua 5xx, halaman error, 403/404 pada modul
// yang dimiliki, dialog error, dan error JavaScript dicatat ke docs/audit-2026-10-21/role-audit.json.
// Usage: WARUNG_BASE_URL=http://127.0.0.1:8765 node tests/browser/role-audit.cjs
// Jalankan HANYA terhadap schema audit yang di-seed demo (bukan database operasional).
const fs = require('fs'), path = require('path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base = process.env.WARUNG_BASE_URL || 'http://127.0.0.1:8765';
const out = path.resolve('docs/audit-2026-10-21');
fs.mkdirSync(out, {recursive: true});

// Kasir dulu, Superadmin terakhir: form Pengaturan tidak boleh mengubah akun role lain sebelum diuji.
const ROLES = [
    ['cashier', 'kasir.melati@warungkita.id'],
    ['spv', 'spv.melati@warungkita.id'],
    ['outlet_manager', 'manager.melati@warungkita.id'],
    ['ops_admin', 'opsadmin@warungkita.id'],
    ['head_ops', 'headops@warungkita.id'],
    ['superadmin', 'superadmin@warungkita.id'],
];
const MODULE_URLS = {
    dashboard: '/dashboard', pos: '/kasir', reservations: '/reservasi', transactions: '/transaksi', products: '/produk',
    inventory: '/gudang', assets: '/inventaris', purchases: '/pembelian', expenses: '/pengeluaran', members: '/member',
    reports: '/laporan', settings: '/pengaturan',
};
const ROLE_MODULES = {
    head_ops: ['dashboard', 'pos', 'reservations', 'transactions', 'products', 'inventory', 'assets', 'purchases', 'expenses', 'members', 'reports'],
    ops_admin: ['pos', 'reservations', 'transactions', 'products', 'inventory', 'assets', 'purchases', 'expenses', 'members'],
    outlet_manager: ['pos', 'reservations', 'transactions', 'inventory', 'assets', 'expenses', 'members'],
    spv: ['pos', 'reservations', 'transactions', 'inventory', 'assets', 'expenses', 'members'],
    cashier: ['pos', 'reservations', 'transactions', 'inventory', 'expenses', 'members'],
    superadmin: Object.keys(MODULE_URLS),
};
// Aksi yang merusak sesi audit (logout, maintenance, menonaktifkan cabang/akun) diuji lewat feature test.
const SKIP = [/\/logout$/, /\/pengaturan\/pemeliharaan$/, /\/switch-store$/, /\/pengaturan\/cabang\/\{id\}\/status$/,
    /\/pengaturan\/pengguna\/\{id\}(\/status)?$/, /\/kasir\/tutup-harian$/];

const findings = [];
const log = [];
const note = (role, scope, kind, url, detail) => {
    findings.push({role, scope, kind, url, detail});
    console.log(`  !! [${role}/${scope}] ${kind} ${url} :: ${String(detail).slice(0, 300)}`);
};
const pattern = (url) => url.replace(base, '').replace(/\?.*$/, '').replace(/\/\d+(?=\/|$)/g, '/{id}');

async function errorText(p) {
    return p.evaluate(() => {
        const text = document.body ? document.body.innerText : '';
        if (/Oops! An Error Occurred|Internal Server Error|Server Error|Whoops/.test(text) || document.querySelector('.ignition, #app[data-ignition]') || /Exception|Error\b/.test(document.title)) {
            return (document.title + ' | ' + text).slice(0, 600);
        }
        return null;
    }).catch(() => null);
}
const alertText = (p) => p.evaluate(() => {
    const err = document.querySelector('.alert-error'), ok = document.querySelector('.alert-success');
    return {error: err ? err.innerText.trim() : null, success: ok ? ok.innerText.trim() : null};
}).catch(() => ({}));

async function session(browser, role, email) {
    const ctx = await browser.newContext({viewport: {width: 1366, height: 900}, acceptDownloads: true});
    const p = await ctx.newPage();
    const state = {role, scope: 'store', dialogs: []};
    p.on('pageerror', (e) => note(role, state.scope, 'js-error', p.url(), e.message));
    p.on('console', (m) => { if (m.type() === 'error' && !/favicon|ERR_|net::/.test(m.text())) note(role, state.scope, 'console-error', p.url(), m.text()); });
    p.on('response', (r) => {
        const s = r.status();
        if (s >= 500) note(role, state.scope, 'http-' + s, r.request().method() + ' ' + r.url(), '');
        else if ([403, 404, 405, 419].includes(s) && r.url().startsWith(base)) note(role, state.scope, 'http-' + s, r.request().method() + ' ' + r.url(), '');
        else if (s === 422) log.push({role, scope: state.scope, kind: 'json-422', url: r.request().method() + ' ' + r.url()});
    });
    p.on('dialog', async (d) => {
        state.dialogs.push(d.message());
        if (d.type() === 'alert' && !/berhasil|terkirim/i.test(d.message())) log.push({role, scope: state.scope, kind: 'alert', url: p.url(), detail: d.message()});
        if (d.type() === 'prompt') await d.accept('Audit otomatis 21 Oktober'); else await d.accept();
    });
    await p.goto(base + '/login');
    await p.fill('[name=email]', email);
    await p.fill('[name=password]', 'password');
    await Promise.all([p.waitForURL((u) => !u.pathname.startsWith('/login'), {timeout: 15000}), p.click('button.btn-primary')]);
    return {ctx, p, state};
}

// Isi form secara heuristik di browser; mengembalikan false bila form butuh file wajib.
async function fillAndSubmit(p, formIndex) {
    return p.evaluate((index) => {
        const form = document.querySelectorAll('form')[index];
        if (!form) return 'missing';
        const stamp = Date.now().toString().slice(-6);
        const today = new Date().toISOString().slice(0, 10);
        for (const el of form.elements) {
            if (!el.name || el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') continue;
            const name = el.name.toLowerCase();
            if (el.type === 'file') { if (el.required) return 'file-required'; continue; }
            if (el.type === 'checkbox' || el.type === 'radio') { if (el.required) el.checked = true; continue; }
            if (el.tagName === 'SELECT') {
                if (el.multiple) { if (el.options.length) el.options[0].selected = true; continue; }
                if (!el.value) { const opt = [...el.options].find((o) => o.value !== '' && !o.disabled); if (opt) el.value = opt.value; }
                el.dispatchEvent(new Event('change', {bubbles: true}));
                continue;
            }
            if (el.value !== '' && !/name|description|notes|reason|sku|code|email/.test(name)) continue;
            let value;
            if (el.type === 'email' || name.includes('email')) value = `audit${stamp}${index}@warungkita.id`;
            else if (el.type === 'password' || /pin/.test(name)) value = /pin/.test(name) ? '1234' : 'password123';
            else if (el.type === 'date') value = today;
            else if (el.type === 'datetime-local') value = today + 'T12:00';
            else if (el.type === 'time') value = '12:00';
            else if (el.type === 'tel' || /phone|telp|hp/.test(name)) value = '0812' + stamp + '1';
            else if (el.type === 'number' || 'moneyInput' in el.dataset) {
                if (el.value !== '' && Number(el.value) > 0) continue;
                const min = parseFloat(el.min); value = String(Math.max(isNaN(min) ? 1 : min, /percent|persen|rate|tax|service/.test(name) ? 5 : 1) * (/price|harga|amount|nominal|cash|kas|deposit|cost|biaya|total|paid/.test(name) ? 10000 : 1));
            } else if (el.type === 'color') value = '#336699';
            else if (/sku|code|kode|barcode/.test(name)) value = 'AUD' + stamp + index;
            else if (el.tagName === 'TEXTAREA' || /notes|reason|description|keterangan|alasan/.test(name)) value = 'Audit otomatis ' + stamp;
            else if (el.type === 'url') value = 'https://example.com';
            else value = (el.maxLength > 0 && el.maxLength < 12) ? 'A' + stamp.slice(0, el.maxLength - 1) : 'Audit ' + stamp;
            el.value = value;
            el.dispatchEvent(new Event('input', {bubbles: true}));
            el.dispatchEvent(new Event('change', {bubbles: true}));
        }
        if (!form.checkValidity()) {
            const bad = [...form.elements].filter((e) => !e.checkValidity()).map((e) => e.name + ':' + e.validationMessage);
            return 'invalid ' + bad.join(', ');
        }
        setTimeout(() => form.requestSubmit ? form.requestSubmit() : form.submit(), 0);
        return 'submitted';
    }, formIndex);
}

async function describeForms(p) {
    return p.evaluate(() => [...document.querySelectorAll('form')].map((f, i) => {
        const spoof = f.querySelector('input[name=_method]');
        return {i, action: f.action, hasAction: !!f.getAttribute('action'), method: (spoof ? spoof.value : f.method || 'get').toUpperCase(), label: (f.closest('.modal')?.querySelector('h2')?.innerText || f.querySelector('button')?.innerText || '').trim().slice(0, 60)};
    }));
}

async function sweepPage(ctx, p, state, url, seenLinks, seenForms) {
    const {role, scope} = state;
    const res = await p.goto(base + url, {waitUntil: 'load'}).catch((e) => ({status: () => 0, err: e.message}));
    const status = res.status();
    const err = await errorText(p);
    if (err) note(role, scope, 'error-page', url, err);
    log.push({role, scope, kind: 'page', url, status});
    if (status !== 200) return;

    // Semua link internal (cetak, export, riwayat, template) dicek dengan request ber-cookie yang sama.
    const links = await p.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.href).filter((h) => h.startsWith(location.origin) && !h.includes('#') && !/logout/.test(h)));
    for (const link of links) {
        const key = pattern(link) + (link.includes('?') ? '?' + link.split('?')[1].replace(/=\d+/g, '=n') : '');
        if (seenLinks.has(key)) continue;
        seenLinks.add(key);
        const r = await ctx.request.get(link, {maxRedirects: 0}).catch((e) => null);
        if (!r) { note(role, scope, 'link-failed', link, 'request error'); continue; }
        const s = r.status();
        if (s >= 500 || s === 404 || s === 405) note(role, scope, 'link-' + s, link, (await r.text()).slice(0, 300));
        else if (s === 403) note(role, scope, 'link-403', link, 'link tampil tetapi ditolak');
        else if (s === 302) {
            const loc = r.headers()['location'] || '';
            if (/login|setup/.test(loc)) note(role, scope, 'link-redirect', link, loc);
        }
        log.push({role, scope, kind: 'link', url: link, status: s});
    }

    // Satu form per pola aksi (id diganti {id}), dikirim dengan isian heuristik.
    const forms = await describeForms(p);
    for (const form of forms) {
        // Form tanpa action diisi JS saat modal dibuka; diuji lewat tombol pemicunya di bawah.
        if (form.method === 'GET' || !form.hasAction) continue;
        const key = form.method + ' ' + pattern(form.action);
        if (seenForms.has(key) || SKIP.some((rx) => rx.test(pattern(form.action)))) continue;
        seenForms.add(key);
        await p.goto(base + url, {waitUntil: 'load'});
        const fresh = await describeForms(p);
        const target = fresh.find((f) => f.method === form.method && pattern(f.action) === pattern(form.action));
        if (!target) continue;
        state.dialogs = [];
        const navigation = p.waitForNavigation({timeout: 12000}).catch(() => null);
        const result = await fillAndSubmit(p, target.i);
        if (result !== 'submitted') { log.push({role, scope, kind: 'form-skipped', url: key, detail: result, page: url}); continue; }
        const response = await navigation;
        await p.waitForLoadState('load').catch(() => {});
        const pageError = await errorText(p);
        const alerts = await alertText(p);
        const entry = {role, scope, kind: 'form', page: url, url: key, label: target.label, status: response ? response.status() : null, final: p.url().replace(base, ''), error: alerts.error, success: alerts.success, dialogs: state.dialogs.slice()};
        log.push(entry);
        if (pageError) note(role, scope, 'form-error-page', key, pageError);
        console.log(`  form ${key} (${target.label}) -> ${entry.final} ${alerts.success ? 'OK: ' + alerts.success.slice(0, 80) : ''}${alerts.error ? 'ERR: ' + alerts.error.slice(0, 120) : ''}`);
    }

    // Form edit di modal: action diisi JS saat tombol (edit-*, *-trigger) diklik.
    const triggers = await p.evaluate(() => [...new Set([...document.querySelectorAll('button[class*="-trigger"], button[class*="edit-"], button.cancel-reservation')]
        .flatMap((b) => [...b.classList].filter((c) => /-trigger$|^edit-|^cancel-reservation$/.test(c))))]);
    for (const trigger of triggers) {
        const key = 'trigger ' + trigger;
        if (seenForms.has(key)) continue;
        seenForms.add(key);
        await p.goto(base + url, {waitUntil: 'load'});
        const button = p.locator('button.' + trigger).first();
        if (!(await button.isVisible().catch(() => false))) { log.push({role, scope, kind: 'form-skipped', url: key, detail: 'trigger hidden', page: url}); continue; }
        state.dialogs = [];
        await button.click();
        await p.waitForTimeout(300);
        const formIndex = await p.evaluate(() => {
            const forms = [...document.querySelectorAll('form')];
            const form = forms.find((f) => f.closest('.modal.open') && (f.querySelector('input[name=_method]') || f.method.toLowerCase() === 'post'));
            return form ? forms.indexOf(form) : -1;
        });
        if (formIndex < 0) { log.push({role, scope, kind: 'form-skipped', url: key, detail: 'no modal form', page: url}); continue; }
        const action = await p.evaluate((i) => document.querySelectorAll('form')[i].action, formIndex);
        const navigation = p.waitForNavigation({timeout: 12000}).catch(() => null);
        const result = await fillAndSubmit(p, formIndex);
        if (result !== 'submitted') { log.push({role, scope, kind: 'form-skipped', url: key, detail: result, page: url}); continue; }
        const response = await navigation;
        await p.waitForLoadState('load').catch(() => {});
        const pageError = await errorText(p);
        const alerts = await alertText(p);
        log.push({role, scope, kind: 'form', page: url, url: key + ' ' + pattern(action), status: response ? response.status() : null, final: p.url().replace(base, ''), error: alerts.error, success: alerts.success, dialogs: state.dialogs.slice()});
        if (pageError) note(role, scope, 'form-error-page', key, pageError);
        console.log(`  ${key} ${pattern(action)} -> ${p.url().replace(base, '')} ${alerts.success ? 'OK: ' + alerts.success.slice(0, 80) : ''}${alerts.error ? 'ERR: ' + alerts.error.slice(0, 120) : ''}`);
    }
}

async function posFlow(p, state) {
    await p.goto(base + '/kasir');
    const card = p.locator('.product-card:not([disabled])').first();
    if (!(await card.count())) { log.push({role: state.role, kind: 'pos', detail: 'tidak ada produk tersedia'}); return; }
    await card.click();
    if (await p.locator('#price-picker-modal.open .price-choice, #price-picker-modal.show .price-choice').count()) await p.locator('#price-picker-modal .price-choice').first().click();
    if (await p.locator('#table-number').isVisible().catch(() => false)) await p.fill('#table-number', 'A1');
    await p.fill('#paid-amount', '500000').catch(() => {});
    await p.locator('#paid-amount').dispatchEvent('input').catch(() => {});
    const checkout = p.waitForResponse((r) => r.url().includes('/kasir/checkout'), {timeout: 15000}).catch(() => null);
    await p.click('#checkout-btn');
    const r = await checkout;
    const body = r ? await r.text().catch(() => '') : '';
    log.push({role: state.role, scope: state.scope, kind: 'pos-checkout', status: r && r.status(), detail: body.slice(0, 200)});
    if (!r || r.status() !== 200) note(state.role, state.scope, 'pos-checkout', '/kasir/checkout', (r ? r.status() + ' ' : 'no response ') + body.slice(0, 300) + ' ' + state.dialogs.join(' | '));
    await p.waitForTimeout(1500);
    for (const pg of p.context().pages()) if (pg !== p) await pg.close();
}

async function inventoryFlow(p, state) {
    await p.goto(base + '/gudang');
    for (const name of ['incoming_quantity', 'used_quantity', 'waste_quantity', 'produced_quantity', 'consumption_quantity', 'notes']) {
        const input = p.locator(`input[name=${name}][data-live-stock]:not([disabled])`).first();
        if (!(await input.count())) continue;
        const current = await input.inputValue();
        const next = name === 'notes' ? 'Audit ' + Date.now() : String(Number(current || 0) + 1);
        state.dialogs = [];
        const resp = p.waitForResponse((r) => r.url().includes('/gudang/stock/'), {timeout: 10000}).catch(() => null);
        await input.fill(next);
        await input.dispatchEvent('change');
        const r = await resp;
        if (!r || r.status() !== 200) note(state.role, state.scope, 'live-edit', name, (r ? r.status() + ' ' + (await r.text()).slice(0, 200) : 'no response') + ' ' + state.dialogs.join(' | '));
        // Halaman reload sendiri setelah tersimpan.
        await p.waitForTimeout(1500);
        await p.waitForLoadState('load').catch(() => {});
        await p.goto(base + '/gudang').catch(() => p.waitForLoadState('load'));
    }
}

(async () => {
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    for (const [role, email] of ROLES) {
        console.log(`== ${role} (${email})`);
        const {ctx, p, state} = await session(browser, role, email);

        // Tiering: modul yang dimiliki terbuka, sisanya 403.
        for (const [module, url] of Object.entries(MODULE_URLS)) {
            const r = await ctx.request.get(base + url, {maxRedirects: 0});
            const allowed = ROLE_MODULES[role].includes(module);
            if (allowed && r.status() !== 200) note(role, 'store', 'tiering', url, 'modul dimiliki tetapi status ' + r.status());
            if (!allowed && r.status() !== 403) note(role, 'store', 'tiering', url, 'modul tidak dimiliki tetapi status ' + r.status());
        }

        // AUDIT_PAGES=/member,/kasir membatasi sweep ke halaman tertentu (tanpa alur kasir/stok dan consolidated).
        if (process.env.AUDIT_PAGES) {
            for (const url of process.env.AUDIT_PAGES.split(',')) if (ROLE_MODULES[role].some((m) => MODULE_URLS[m] === url)) await sweepPage(ctx, p, state, url, new Set(), new Set());
            await ctx.close();
            continue;
        }
        if (ROLE_MODULES[role].includes('pos')) await posFlow(p, state);
        if (ROLE_MODULES[role].includes('inventory')) await inventoryFlow(p, state);

        const seenLinks = new Set(), seenForms = new Set();
        for (const module of ROLE_MODULES[role]) await sweepPage(ctx, p, state, MODULE_URLS[module], seenLinks, seenForms);
        await sweepPage(ctx, p, state, '/kasir/tutup-harian', seenLinks, seenForms);

        // Head of Ops & Superadmin: ulangi seluruh halaman dalam mode consolidated.
        if (['head_ops', 'superadmin'].includes(role)) {
            await p.goto(base + '/kasir');
            await Promise.all([p.waitForNavigation(), p.selectOption('#active-store', 'consolidated')]);
            state.scope = 'consolidated';
            const seenLinksC = new Set(), seenFormsC = new Set();
            for (const module of ROLE_MODULES[role]) await sweepPage(ctx, p, state, MODULE_URLS[module], seenLinksC, seenFormsC);
            if (ROLE_MODULES[role].includes('inventory')) await inventoryFlow(p, state);
            await p.goto(base + '/kasir');
            const second = await p.evaluate(() => [...document.querySelectorAll('#active-store option')].map((o) => o.value).filter((v) => v !== 'consolidated').pop());
            await Promise.all([p.waitForNavigation(), p.selectOption('#active-store', second)]);
            state.scope = 'store-2';
            if (ROLE_MODULES[role].includes('pos')) await posFlow(p, state);
            for (const url of ['/kasir', '/gudang', '/transaksi', '/kasir/tutup-harian']) await sweepPage(ctx, p, state, url, new Set(), new Set(['skip-forms']));
        }
        await ctx.close();
    }
    await browser.close();
    fs.writeFileSync(path.join(out, 'role-audit.json'), JSON.stringify({findings, log}, null, 2));
    console.log(`\nfindings: ${findings.length}`);
})().catch((e) => { console.error(e); fs.writeFileSync(path.join(out, 'role-audit.json'), JSON.stringify({findings, log, crash: String(e.stack)}, null, 2)); process.exit(1); });
