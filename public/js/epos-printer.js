/*
 * Cetak struk ke printer Epson lewat ePOS-Print (XML over HTTP).
 * Browser mengirim XML langsung ke printer di jaringan lokal kasir, sehingga
 * printer harus mendukung ePOS-Print (mis. TM-m30, TM-T82III/X, TM-T88VI) dan
 * layanan ePOS-Print di printer diaktifkan melalui EpsonNet Config / Web Config.
 *
 * Aplikasi yang dibuka lewat HTTPS hanya dapat mengirim ke printer dengan HTTPS
 * aktif (buka https://IP-printer sekali untuk menerima sertifikatnya).
 * File dibaca langsung oleh browser sehingga tidak memerlukan npm run build.
 */
(function (global) {
    const NAMESPACE = 'http://www.epson-pos.com/schemas/2011/03/epos-print';
    const ERRORS = {
        EPTR_COVER_OPEN: 'Tutup printer terbuka.',
        EPTR_REC_EMPTY: 'Kertas struk habis.',
        EPTR_AUTOMATICAL: 'Printer mengalami error otomatis yang dapat pulih. Periksa kertas/cutter.',
        EPTR_CUTTER: 'Pisau pemotong printer macet.',
        EPTR_MECHANICAL: 'Printer mengalami gangguan mekanis.',
        EPTR_UNRECOVERABLE: 'Printer mengalami error yang tidak dapat pulih. Matikan lalu nyalakan kembali.',
        EX_TIMEOUT: 'Printer tidak merespons sebelum batas waktu.',
        EX_BADPORT: 'Port printer tidak dapat dibuka.',
        DeviceNotFound: 'Device ID printer tidak ditemukan. Periksa pengaturan device ID (default local_printer).',
        SchemaError: 'Format perintah cetak tidak dikenali printer.',
        PrintSystemError: 'Sistem cetak printer sedang bermasalah.',
    };

    const escapeXml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;',
    })[char]);

    /** Ubah daftar perintah dari server (text/feed/cut/pulse) menjadi dokumen ePOS-Print. */
    function buildXml(commands) {
        const body = (commands || []).map((command) => {
            switch (command.type) {
                case 'text': {
                    const align = ['left', 'center', 'right'].includes(command.align) ? command.align : 'left';
                    const wide = command.double ? 'true' : 'false';
                    const tall = command.double || command.tall ? 'true' : 'false';
                    return `<text lang="en" smooth="true" align="${align}" em="${command.bold ? 'true' : 'false'}" dw="${wide}" dh="${tall}">${escapeXml(command.text)}&#10;</text>`;
                }
                case 'feed':
                    return `<feed line="${Math.max(1, Math.min(10, Number(command.lines) || 1))}"/>`;
                case 'cut':
                    return '<cut type="feed"/>';
                case 'pulse':
                    return '<pulse drawer="drawer_1" time="pulse_100"/>';
                default:
                    return '';
            }
        }).join('');

        return '<?xml version="1.0" encoding="utf-8"?>'
            + '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            + `<epos-print xmlns="${NAMESPACE}">${body}</epos-print>`
            + '</s:Body></s:Envelope>';
    }

    /** Baca atribut success/code dari respons SOAP printer. */
    function parseResponse(text) {
        const match = String(text || '').match(/<response\b[^>]*>/i);
        if (!match) return { success: false, code: 'NO_RESPONSE' };
        const attribute = (name) => (match[0].match(new RegExp(`${name}="([^"]*)"`, 'i')) || [])[1] || '';

        return { success: attribute('success') === 'true', code: attribute('code'), status: attribute('status') };
    }

    function errorMessage(code) {
        return ERRORS[code] || (code ? `Printer menolak perintah (${code}).` : 'Printer tidak memberikan respons yang dikenali.');
    }

    function mixedContentBlocked(printer) {
        return global.location?.protocol === 'https:' && !String(printer.url).startsWith('https:');
    }

    async function sendJob(printer, commands, fetcher = global.fetch?.bind(global)) {
        if (mixedContentBlocked(printer)) {
            throw new Error('Aplikasi dibuka lewat HTTPS, tetapi printer memakai HTTP. Aktifkan HTTPS pada printer dan centang "Printer memakai HTTPS" di Pengaturan.');
        }
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? setTimeout(() => controller.abort(), (Number(printer.timeout) || 10000) + 5000) : null;
        let response;
        try {
            response = await fetcher(printer.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'text/xml; charset=utf-8',
                    'If-Modified-Since': 'Thu, 01 Jan 1970 00:00:00 GMT',
                    SOAPAction: '""',
                },
                body: buildXml(commands),
                signal: controller?.signal,
            });
        } catch (error) {
            const name = printer.name ? ` ${printer.name}` : '';
            throw new Error(`Printer${name} tidak dapat dihubungi. Pastikan printer menyala, satu jaringan dengan perangkat kasir, dan alamat IP benar.`);
        } finally {
            if (timer) clearTimeout(timer);
        }
        const result = parseResponse(await response.text());
        if (!response.ok || !result.success) throw new Error(errorMessage(result.code));

        return result;
    }

    /** Cetak semua lembar (customer lalu dapur) secara berurutan. */
    async function printJobs(payload, fetcher) {
        const printer = payload?.printer || state.printer;
        if (!printer) throw new Error('Printer Epson belum diatur.');
        for (const job of payload.jobs || []) {
            await sendJob(printer, job, fetcher);
        }
    }

    const state = { printer: null };

    global.PosPrinter = {
        configure(printer) { state.printer = printer || null; },
        isReady() { return Boolean(state.printer && state.printer.auto_print); },
        printer() { return state.printer; },
        buildXml,
        parseResponse,
        errorMessage,
        sendJob,
        printJobs,
        testPage(printer, fetcher) {
            const line = '-'.repeat(Math.max(24, Math.min(64, Number(printer.columns) || 42)));
            return sendJob(printer, [
                { type: 'text', text: 'TES PRINTER EPSON ePOS', align: 'center', bold: true, double: true },
                { type: 'text', text: printer.name || '', align: 'center' },
                { type: 'text', text: line },
                { type: 'text', text: `Lebar ${printer.columns || 42} karakter per baris` },
                { type: 'text', text: new Date().toLocaleString('id-ID') },
                { type: 'text', text: line },
                { type: 'text', text: 'Printer siap dipakai kasir.', align: 'center' },
                { type: 'feed', lines: 3 },
                { type: 'cut' },
            ], fetcher);
        },
    };
})(typeof window !== 'undefined' ? window : globalThis);
