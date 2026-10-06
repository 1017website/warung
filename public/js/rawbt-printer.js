(function (global) {
    'use strict';

    const isAndroid = () => /Android/i.test(global.navigator?.userAgent || '');
    global.RawbtPrinter = { isAndroid };
    global.document?.addEventListener('click', event => {
        const link = event.target.closest?.('.rawbt-link');
        if (!link) return;
        if (!isAndroid()) {
            event.preventDefault();
            global.alert('RawBT hanya tersedia di Android. Gunakan tablet Android yang sudah dipasangi RawBT.');
            return;
        }
        const status = global.document.getElementById('rawbt-status') || global.document.getElementById('rawbt-test-status');
        if (status) status.textContent = 'Membuka RawBT. Jika tidak terbuka, pasang RawBT dan pilih printer Bluetooth di pengaturannya. Periksa hasil cetak sebelum mencetak ulang.';
    });
})(typeof window !== 'undefined' ? window : globalThis);
