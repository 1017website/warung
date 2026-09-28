const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/epos-printer.js'), 'utf8');

function loadPrinter(protocol = 'http:') {
    const context = vm.createContext({ location: { protocol }, setTimeout, clearTimeout, AbortController });
    context.window = context;
    vm.runInContext(source, context);
    return context.PosPrinter;
}

const printer = { name: 'TM-m30', url: 'http://192.168.1.50/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', timeout: 10000, columns: 42, auto_print: true };

test('commands become an ePOS-Print SOAP document with escaped text', () => {
    const xml = loadPrinter().buildXml([
        { type: 'pulse' },
        { type: 'text', text: 'Warung <A&B>', align: 'center', bold: true, double: true },
        { type: 'text', text: '2 x Es Teh', tall: true },
        { type: 'feed', lines: 3 },
        { type: 'cut' },
    ]);
    assert.match(xml, /^<\?xml version="1\.0" encoding="utf-8"\?><s:Envelope/);
    assert.match(xml, /<epos-print xmlns="http:\/\/www\.epson-pos\.com\/schemas\/2011\/03\/epos-print">/);
    assert.match(xml, /<pulse drawer="drawer_1" time="pulse_100"\/>/);
    assert.match(xml, /align="center" em="true" dw="true" dh="true">Warung &lt;A&amp;B&gt;&#10;<\/text>/);
    assert.match(xml, /dw="false" dh="true">2 x Es Teh&#10;/);
    assert.match(xml, /<feed line="3"\/><cut type="feed"\/><\/epos-print>/);
});

test('printer response is parsed and error codes are translated', () => {
    const epos = loadPrinter();
    assert.deepEqual(
        { ...epos.parseResponse('<s:Envelope><s:Body><response success="true" code="" status="251658262" xmlns="x"/></s:Body></s:Envelope>') },
        { success: true, code: '', status: '251658262' }
    );
    assert.equal(epos.parseResponse('<response success="false" code="EPTR_REC_EMPTY" status="0"/>').success, false);
    assert.equal(epos.errorMessage('EPTR_REC_EMPTY'), 'Kertas struk habis.');
    assert.match(epos.errorMessage('ABC'), /ABC/);
});

test('jobs are sent in order with ePOS headers and stop on a printer error', async () => {
    const epos = loadPrinter();
    const calls = [];
    const fetcher = async (url, options) => {
        calls.push({ url, options });
        const code = calls.length === 2 ? 'EPTR_COVER_OPEN' : '';
        return { ok: true, text: async () => `<response success="${code ? 'false' : 'true'}" code="${code}"/>` };
    };
    await assert.rejects(epos.printJobs({ printer, jobs: [[{ type: 'cut' }], [{ type: 'cut' }], [{ type: 'cut' }]] }, fetcher), /Tutup printer terbuka/);
    assert.equal(calls.length, 2);
    assert.equal(calls[0].url, printer.url);
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['Content-Type'], 'text/xml; charset=utf-8');
    assert.equal(calls[0].options.headers.SOAPAction, '""');
});

test('unreachable printer and https mixed content give clear messages', async () => {
    await assert.rejects(loadPrinter().sendJob(printer, [], async () => { throw new TypeError('Failed to fetch'); }), /tidak dapat dihubungi/);
    await assert.rejects(loadPrinter('https:').sendJob(printer, [], async () => ({ ok: true, text: async () => '' })), /HTTPS/);
});

test('auto print only when configured', () => {
    const epos = loadPrinter();
    assert.equal(epos.isReady(), false);
    epos.configure({ ...printer, auto_print: false });
    assert.equal(epos.isReady(), false);
    epos.configure(printer);
    assert.equal(epos.isReady(), true);
});
