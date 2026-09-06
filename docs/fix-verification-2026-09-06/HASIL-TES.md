# Hasil perbaikan dan tes ulang

6 September 2026. Seluruh tujuh temuan audit awal sudah diperbaiki. Satu masalah tambahan pada pengisian angka di modal Koreksi Pembelian juga diperbaiki saat pemeriksaan nilai database.

## Perubahan dan bukti

| ID | Perbaikan | Bukti hasil ulang |
|---|---|---|
| B01 | Pembayaran memakai nominal tunai asli; pembayaran kurang ditolak sebelum request. Sisa deposit yang dibayar tunai menampilkan input uang diterima. | Rp0/Rp1.000 ditolak untuk tagihan Rp25.000; tunai Rp30.000 tersimpan dengan kembalian Rp5.000; split deposit Rp5.000 + tunai Rp20.000 sesuai database. |
| B02 | Autosubmit pembelian memakai requestSubmit agar pemisah ribuan dinormalisasi. | DP Rp1.000, Rp5.000, dan Rp1.000.000 tetap sama setelah reload. |
| B03 | Kasir, Produk, dan Ringkasan menyiapkan stok harian menggunakan aturan carryover yang sama dengan Gudang. | Stok kemarin 7 pcs langsung terlihat sebelum Gudang dibuka; stok hari ini yang sudah nol tidak diisi ulang; cabang berbeda tetap terisolasi. |
| B04 | Tombol Keluar tersedia pada baris akun di bawah header untuk layar ≤820 px. | Logout nyata berhasil pada 768, 390, dan 360 px; akses halaman sesudah logout kembali ke login. |
| B05 | Koreksi pembelian memakai selisih bersih stok; metadata tidak menulis mutasi stok. Baris stok dikunci ketika diperbarui. | Nama supplier dan catatan dapat dikoreksi saat sisa stok 2 dari penerimaan 10; stok tetap 2. Koreksi yang membuat stok negatif ditolak dan di-rollback. |
| B06 | Tautan antar-menu diperiksa sesuai izin, termasuk Transaksi baru serta tautan Ringkasan ke Gudang/Transaksi. | Role Transaksi saja dan Ringkasan saja tidak mendapat tombol menuju modul terlarang; backend tetap mengembalikan 403 untuk modul di luar izin. |
| B07 | Tautan riil/non-riil mempertahankan from/to. | Periode 1–15 Agustus 2026 tetap sama pada perpindahan dua arah. |
| Tambahan | Angka desimal dari database dikonversi ke angka sebelum formatter modal Koreksi Pembelian. | Harga Rp200.000 dan DP Rp1.000.000 tidak menjadi 100 kali lipat; total pembelian tetap Rp2.000.000 setelah koreksi supplier. |

## Pengujian

- Backend: **125 tes lulus, 709 assertion**, termasuk 12 kasus baru untuk carryover, pembayaran kurang, koreksi pembelian, role khusus, dan periode laporan.
- JavaScript: **16 tes lulus**, termasuk uang kosong/kurang/pas/lebih, split deposit, pemuatan angka modal pembelian, dan perilaku cetak/popup.
- Browser Edge: **55 pemeriksaan halaman** pada lebar 1440, 1024, 768, 390, dan 360 px; halaman 200, tidak ada overflow horizontal dokumen, tombol logout tersedia.
- Hak akses browser: **90 pemeriksaan URL** untuk tujuh role bawaan dan dua role khusus; status 200/403 sesuai izin.
- Database: **9 pemeriksaan nilai tersimpan lulus**, termasuk jumlah transaksi, pembayaran, kembalian, saldo, stok, DP, supplier, dan total pembelian.
- Transaksi serentak: **5 skenario / 68 request lulus** untuk perebutan stok, saldo deposit, urutan penguncian produk, pembatalan ganda, dan penyesuaian stok. Stok/saldo tidak negatif dan refund tidak berganda.
- Pemeriksaan whitespace: `git diff --check` lulus.

Perekaman ulang screenshot sempat mengalami kegagalan penulisan file Windows. Pemeriksaan role dilanjutkan dari role terakhir yang selesai; kegagalan tersebut bukan hasil respons aplikasi. Hasil akhir hanya dinyatakan lulus setelah seluruh role selesai dan berkas screenshot diperiksa.

## Bukti dan cara menjalankan

- [Hasil browser dan role](browser-results.json)
- [Pemeriksaan nilai database](database-results.json)
- [Hasil transaksi serentak](concurrency-results.json)
- [Galeri screenshot](screenshots/)
- [User guide per hak akses](../user-guide/index.html)

Tes backend: `php artisan test` (konfigurasi repository memakai `warungkita_test`).

Tes JavaScript: `node --test tests/js/payment-amount.test.cjs tests/js/receipt-checkout.test.cjs tests/js/purchase-modal.test.cjs`.

Tes concurrency: `python tests/Concurrency/run.py`. Jangan dijalankan bersamaan dengan PHPUnit karena keduanya memakai database tes yang sama.

Tes browser: gunakan schema audit terpisah `warungkita_audit_20260906`, jalankan aplikasi lokal pada port 8017 dengan konfigurasi schema tersebut, lalu jalankan `tests/browser/audit-fixture.php` dan simpan JSON-nya ke `storage/app/fix-verification/fixture.json`. Jalankan `node tests/browser/audit-fixes.cjs`, lalu `tests/browser/verify-audit-database.php` pada schema yang sama. `PLAYWRIGHT_MODULE` dapat diisi dengan lokasi modul Playwright pada mesin lain. Fixture menambah tenant demo baru, tidak mereset database utama.

## Batas hasil

Perubahan tersedia pada workspace lokal. Tidak ada deployment ke server produksi atau perubahan `.env`/database utama yang dilakukan dalam pekerjaan ini. Perbaikan tidak otomatis mengubah transaksi historis yang mungkin sudah tersimpan dengan nominal salah.

Hasil ini membuktikan skenario yang diuji, bukan seluruh kemungkinan alur. Kamera, printer, Safari, perangkat Android/iOS fisik, dan semua variasi berkas impor belum diuji langsung. User guide memakai screenshot data demo dari versi setelah perbaikan.
