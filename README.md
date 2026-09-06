# POS Warung — POS Multi Warung

Sistem operasional warung makan berbasis Laravel 12 dan MySQL. Mencakup kasir, menu, stok gudang, pembelian, pengeluaran, membership deposit dengan QR, laporan riil/non-riil otomatis, multi-cabang, role pengguna, branding, soft delete, dan cetak POS 80 mm.

## Menjalankan aplikasi

Persyaratan: PHP 8.2+, Composer, Node.js, dan MySQL 8+.

1. Buat database MySQL bernama `warungkita`.
2. Salin `.env.example` menjadi `.env`, lalu sesuaikan kredensial MySQL.
3. Jalankan:

```bash
composer install
npm install
php artisan key:generate
php artisan storage:link
php artisan migrate --seed
npm run build
php artisan serve
```

Buka `http://localhost:8000`.

## Akun demo

| Role | Email | Password |
|---|---|---|
| Superadmin | superadmin@warungkita.id | password |
| Head of Ops | headops@warungkita.id | password |
| Ops Admin | opsadmin@warungkita.id | password |
| Outlet Manager | manager.melati@warungkita.id | password |
| SPV | spv.melati@warungkita.id | password |
| Kasir | kasir.melati@warungkita.id | password |

Daftar ini mengikuti seeder demo. Developer adalah role sistem tersendiri; akun Developer tidak dibuat oleh seeder demo ini. Hak akses aktual dibaca dari master role dan sub-izin Pengaturan.

## Panduan dan hasil pengujian

- [User guide per menu dan tujuh hak akses, dengan screenshot](docs/user-guide/index.html)
- [Hasil perbaikan dan tes ulang 6 September 2026](docs/fix-verification-2026-09-06/HASIL-TES.md)
- [Arsip audit sebelum perbaikan](docs/audit-2026-09-06/index.html)

## Catatan desain data

Semua data bisnis membawa `tenant_id`, sedangkan data operasional cabang juga membawa `store_id`. Produk, transaksi, pembelian, pengeluaran, member, tenant, cabang, dan pengguna memakai soft delete. Transaksi yang diarsipkan tidak mengembalikan stok otomatis agar jejak audit tidak berubah diam-diam.

Transaksi kasir masuk sebagai data riil. Akses non-riil mengikuti izin master role; default tersedia untuk Developer dan Superadmin. Persentase laporan mengikuti pengaturan masing-masing cabang. Akun tanpa izin tidak menerima kontrol non-riil dan permintaan laporannya dibatasi server.

Halaman laporan menyediakan ekspor Excel sesuai periode dan jenis laporan aktif. Workbook berisi tiga sheet: ringkasan dengan formula, rincian transaksi, dan rincian pengeluaran. Permintaan ekspor non-riil dari role pegawai otomatis dipaksa menjadi laporan riil.

Scan QR kamera menggunakan `BarcodeDetector` bawaan browser. Gunakan Chrome/Edge modern melalui HTTPS atau localhost agar izin kamera tersedia.
