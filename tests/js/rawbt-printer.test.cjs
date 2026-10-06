const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/rawbt-printer.js'), 'utf8');

for (const userAgent of ['Mozilla Android 12', 'Mozilla iPad', 'Mozilla Windows']) {
    test(`RawBT handoff guard: ${userAgent}`, () => {
        let handler, prevented = false, warning;
        const status = {};
        const window = {
            navigator: { userAgent }, alert: message => { warning = message; },
            document: { addEventListener: (name, callback) => { handler = callback; }, getElementById: () => status }
        };
        vm.runInNewContext(source, { window });
        handler({ target: { closest: () => ({}) }, preventDefault: () => { prevented = true; } });
        if (userAgent.includes('Android')) {
            assert.equal(prevented, false);
            assert.match(status.textContent, /Membuka RawBT/);
            assert.equal(warning, undefined);
        } else {
            assert.equal(prevented, true);
            assert.match(warning, /hanya tersedia di Android/);
        }
    });
}
