# WarungKita — POS Multi Warung

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
| Owner | admin@warungkita.id | password |
| Superadmin | superadmin@warungkita.id | password |
| Admin/Manager | manager@warungkita.id | password |
| Kasir | kasir@warungkita.id | password |
| Gudang | gudang@warungkita.id | password |

## Catatan desain data

Semua data bisnis membawa `tenant_id`, sedangkan data operasional cabang juga membawa `store_id`. Produk, transaksi, pembelian, pengeluaran, member, tenant, cabang, dan pengguna memakai soft delete. Transaksi yang diarsipkan tidak mengembalikan stok otomatis agar jejak audit tidak berubah diam-diam.

Transaksi kasir selalu masuk sebagai data riil. Hanya role `owner` dan `superadmin` yang dapat membuka laporan non-riil, yang dihitung otomatis sebesar 50% dari omzet, HPP, pengeluaran, dan keuntungan riil. Role pegawai tidak menerima kontrol klasifikasi dan tidak dapat menemukan tampilan laporan non-riil meskipun mencoba URL-nya secara langsung.

Halaman laporan menyediakan ekspor Excel sesuai periode dan jenis laporan aktif. Workbook berisi tiga sheet: ringkasan dengan formula, rincian transaksi, dan rincian pengeluaran. Permintaan ekspor non-riil dari role pegawai otomatis dipaksa menjadi laporan riil.

Scan QR kamera menggunakan `BarcodeDetector` bawaan browser. Gunakan Chrome/Edge modern melalui HTTPS atau localhost agar izin kamera tersedia.
