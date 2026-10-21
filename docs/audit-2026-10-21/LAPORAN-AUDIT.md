# Audit seluruh modul per role — 21 Oktober 2026

## Cara audit

1. **Review kode statis.** `WarungController` dan semua view dibagi menjadi tiga bagian lalu diperiksa untuk error runtime: 500, 403/404 yang salah, 422 yang tidak semestinya, nama field form yang tidak cocok dengan validasi, dan error JavaScript.
2. **Crawler browser per role** ([tests/browser/role-audit.cjs](../../tests/browser/role-audit.cjs)) dijalankan pada schema terpisah `warungkita_audit` yang di-seed demo. Untuk setiap role (Kasir, SPV, Outlet Manager, Ops Admin, Head of Ops, Superadmin), crawler:
   - memeriksa tiering: modul yang dimiliki terbuka, modul lain ditolak 403;
   - membuka setiap menu dan memeriksa semua link internal (cetak, export, riwayat, template);
   - mengirim setiap jenis form, termasuk form edit di modal yang dibuka lewat tombolnya;
   - menjalankan alur kasir (checkout) dan live edit stok;
   - untuk Head of Ops dan Superadmin, mengulang semuanya di mode **consolidated** dan di cabang kedua.
3. **Skenario pembayaran kasir** ([tests/browser/pos-payment-audit.cjs](../../tests/browser/pos-payment-audit.cjs)).

Cakupan akhir: 89 halaman, 325 link, 230 form (12 jenis form modal × semua role yang memilikinya), checkout di 8 kombinasi role/cabang, dan modul Member per role. **Hasil pass terakhir: 0 error 500, 0 halaman error, 0 error JavaScript, 0 error baru di `laravel.log`.**

Semua penolakan yang tersisa adalah aturan bisnis yang benar dan tampil sebagai pesan di layar. Contohnya: stok tidak mencukupi, sumber dan hasil proses ulang tidak boleh sama, dan pendaftaran member wajib scan kartu.

## Bug yang ditemukan dan diperbaiki

| # | Modul | Gejala | Perbaikan |
|---|---|---|---|
| 1 | Stok / Gudang | Setelah produk yang pernah diproduksi diarsipkan, **/gudang error 500 untuk semua role** (ditemukan crawler). | Riwayat produksi tetap membaca produk yang sudah diarsipkan (`StockProduction` memakai `withTrashed`). |
| 2 | Semua modul | Penolakan aturan bisnis muncul sebagai halaman "Oops! 422" tanpa pesan. | Penolakan dari form biasa kembali ke halaman asal dengan pesan, dan isian tetap ada (`bootstrap/app.php`). |
| 3 | Pembelian | Koreksi pembelian Lunas ke total yang lebih kecil ditolak dengan pesan "DP melebihi total". | Pengecekan DP hanya dilakukan bila status pembayaran DP (simpan, koreksi, dan ubah status). |
| 4 | Produk → Import | Kategori yang pernah diarsipkan atau barcode yang sudah dipakai produk lain membuat import gagal 500. | Kategori dipulihkan. Barcode bentrok dilewati dan dicatat di pesan hasil import. Jenis "Bahan Baku" dikenali tanpa peka huruf besar/kecil. |
| 5 | Produk → Import | Stok menu yang sudah ada di cabang lain tertimpa nilai Stok Awal, sehingga sisa kemarin tidak terbawa. | Menu yang sudah punya riwayat stok membawa sisa kemarin. Stok Awal hanya dipakai untuk stok baru. |
| 6 | Produk / harga | Harga desimal (mis. 12,5) menjadi 10× lipat saat modal edit disimpan. | `setMoneyInputValue` membulatkan nilai desimal dari database. |
| 7 | Kasir | Klik Deposit tanpa member lalu pindah ke Tunai membuat kasir macet dengan pesan "Pilih member…". | Deposit hanya diisi dan dikirim bila member dipilih, maksimal sebesar saldo. |
| 8 | Kasir | Provider QRIS/transfer ikut tersimpan di pembayaran tunai ("CASH · QRIS BCA"), sehingga rincian tutup kasir terpecah. | Provider hanya dikirim dan disimpan untuk QRIS, transfer, dan debit (di sisi JS dan server). |
| 9 | Kasir | Diskon % desimal ("12,5") dibaca 125%, lalu ditolak atau menjadi diskon yang salah. | Diskon % dan diskon member tidak lagi diformat sebagai rupiah dan menerima desimal. |
| 10 | Kasir | Open bill berisi menu atau member yang sudah nonaktif menghasilkan 404 mentah ("No query results…"). | Kasir menampilkan pesan yang jelas (422). |
| 11 | Pengaturan | Mengaktifkan kembali akun yang cabangnya sudah nonaktif membuat akun itu error 500 di semua halaman. | Pengaktifan ditolak dengan pesan. |
| 12 | Pengaturan | Cabang nonaktif ikut muncul di pilihan akun baru dan perangkat, lalu gagal dengan pesan umum. | Hanya cabang aktif yang ditampilkan, dan pesan penolakan diperjelas. |
| 13 | Pengaturan | Kode role yang pernah dihapus tidak bisa dipakai lagi. | Role lama dipulihkan dengan data baru. |
| 14 | Laporan | Laporan non-riil consolidated menampilkan angka riil untuk cabang nonaktif. | Persentase non-riil cabang nonaktif tetap dipakai. |
| 15 | Member | Total top up / deposit terpakai di riwayat member menjadi Rp 0 saat filter Masuk/Keluar dipilih. | Ringkasan dihitung dari semua mutasi, tidak mengikuti filter. |
| 16 | Struk | Tombol "Kembali ke kasir" ditolak 403 untuk role tanpa modul Kasir. | Tombol diganti "Kembali" ke halaman sebelumnya. |

Tes regresi ada di `tests/Feature/October21AuditTest.php` dan `tests/Feature/October21RevisionTest.php`. Seluruh suite: 203 tes PHP dan 6 tes JS lulus.

## Catatan kecil (belum diubah)

- **Pembelian:** produk yang sudah diarsipkan tidak muncul di pilihan modal koreksi, sehingga pembelian lamanya harus dikoreksi ke produk lain.
- **Pengeluaran:** pengeluaran lama dengan kategori di luar daftar kategori saat ini harus dipilihkan kategori baru saat diedit.
- **Pengaturan:** role kustom satu-cabang yang diberi izin *Cabang* bisa membuat cabang baru, tetapi tidak bisa melihat atau mengelolanya.

## Menjalankan ulang

```bash
php -r '$p=new PDO("mysql:host=127.0.0.1","root","");$p->exec("DROP DATABASE IF EXISTS warungkita_audit");$p->exec("CREATE DATABASE warungkita_audit");'
DB_DATABASE=warungkita_audit php artisan migrate --seed --force
DB_DATABASE=warungkita_audit php artisan serve --port=8765
WARUNG_BASE_URL=http://127.0.0.1:8765 node tests/browser/role-audit.cjs
WARUNG_BASE_URL=http://127.0.0.1:8765 node tests/browser/pos-payment-audit.cjs
```

Hasil mentah disimpan di `role-audit.json` (semua modul) dan `role-audit-member.json` (modul Member setelah ada data member).
