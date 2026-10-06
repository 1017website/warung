const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/M.Zulfi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

// Run php tests/browser/rawbt-preview.php first. Uses unsaved models and no live transactions.
(async () => {
    const html = fs.readFileSync('storage/app/rawbt-preview.html', 'utf8').replace(/<script src="([^"]+)"><\/script>/g,
        (tag, src) => src.includes('rawbt-printer.js') ? '<script>' + fs.readFileSync('public/js/rawbt-printer.js', 'utf8') + '</script>' : '');
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    const errors = [];
    for (const android of [true, false]) {
        const context = await browser.newContext({ userAgent: android ? 'Mozilla/5.0 Android 12' : 'Mozilla/5.0 iPad' });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(html);
        assert.equal(await page.locator('#receipt-method').inputValue(), android ? 'rawbt' : 'browser');
        await page.evaluate(() => {
            window.printCalls = 0;
            window.print = () => window.printCalls++;
            document.addEventListener('click', event => { if (event.target.closest('.rawbt-link')) event.preventDefault(); });
        });
        for (const width of [360, 768, 1024]) {
            await page.setViewportSize({ width, height: 900 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow ${width}`);
        }
        const link = page.locator('#receipt-print');
        assert.equal(await page.locator('.print-action').count(), 1);
        assert.equal(await page.locator('details').getAttribute('open'), null);
        if (!android) {
            await page.locator('summary').click();
            await page.selectOption('#receipt-method', 'rawbt');
            await page.locator('summary').click();
        }
        const data = [];
        for (const copy of ['both', 'customer', 'kitchen']) {
            await page.selectOption('#receipt-copy', copy);
            const href = await link.getAttribute('href');
            assert.match(href, /^intent:base64,.*#Intent;scheme=rawbt;package=ru\.a402d\.rawbtprinter;end;$/);
            data.push(Buffer.from(href.split(',')[1].split('#')[0], 'base64').toString('ascii'));
            if (!android) page.once('dialog', dialog => dialog.accept());
            await link.click();
        }
        assert(data[0].includes('TOTAL') && data[0].includes('DAPUR'));
        assert(data[1].includes('TOTAL') && !data[1].includes('DAPUR'));
        assert(!data[2].includes('TOTAL') && data[2].includes('DAPUR'));
        if (android) assert.match(await page.locator('#rawbt-status').innerText(), /Membuka RawBT/);
        await page.locator('summary').click();
        await page.selectOption('#receipt-method', 'browser');
        await page.locator('summary').click();
        for (const copy of ['both', 'customer', 'kitchen']) {
            await page.selectOption('#receipt-copy', copy);
            await link.click();
            assert.equal(await page.locator('body').getAttribute('data-print-copy'), copy);
        }
        assert.equal(await page.evaluate(() => printCalls), 3);
        await link.focus();
        assert(await link.evaluate(link => link === document.activeElement));
        if (android) {
            await page.locator('summary').click();
            await page.selectOption('#receipt-method', 'rawbt');
            await page.locator('summary').click();
            await page.selectOption('#receipt-copy', 'both');
            await page.setViewportSize({ width: 768, height: 900 });
            await page.screenshot({ path: 'storage/app/rawbt-preview.png', fullPage: true });
        }
        await context.close();
    }
    assert.deepEqual(errors, []);
    await browser.close();
    console.log('RawBT receipt: Android/iPad guards, 3 copy selections, browser printing, focus and 360/768/1024 layout passed.');
})().catch(error => { console.error(error); process.exit(1); });
