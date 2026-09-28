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

## Setup awal (wizard)

Instalasi tanpa data demo (`php artisan migrate` tanpa `--seed`) masuk ke wizard setup awal. Selama data awal belum lengkap, middleware `EnsureInitialSetup` mengalihkan seluruh URL ke `/setup` dan menjawab permintaan JSON dengan kode 409 beserta alamat pengalihan.

Wizard dianggap perlu bila tenant belum ada, `tenants.setup_step` belum kosong, belum ada cabang aktif, atau belum ada role pada tenant. Hanya akun Developer/Superadmin yang dapat mengisinya; role lain melihat halaman tunggu sampai setup selesai.

| Langkah | Isian | Hasil |
|---|---|---|
| 1. Usaha & cabang | Nama usaha, nama cabang, alamat, telepon (opsional), diskon member 0–100, pesan penutup struk (opsional) | Tenant, cabang aktif pertama, dan tujuh role bawaan dibuat; akun diikat ke cabang tersebut |
| 2. Produk awal | Kategori, nama menu, satuan, harga jual (min. Rp1), stok siap jual (min. 0,001; maks. tiga desimal) | Produk jenis `menu` ber-SKU `MENU-…`, stok harian tanggal berjalan, dan pergerakan stok `adjustment_in` bereferensi `SETUP` |
| 3. Siap digunakan | Centang pernyataan kesiapan | `setup_step` dikosongkan setelah menu aktif berharga di atas nol dan hak akses akun terverifikasi |

Progres tersimpan per langkah, sehingga setup dapat dilanjutkan setelah keluar. Pengiriman ulang atau tab lama tidak menggandakan data karena langkah yang sudah selesai diabaikan. Bila cabang aktif hilang saat langkah 2, wizard kembali ke langkah 1 disertai pesan; role bawaan yang diarsipkan dipulihkan saat langkah 1 dijalankan ulang.

Langkah lengkap beserta tangkapan layar ada pada [panduan Superadmin](docs/user-guide/superadmin.html#setup) dan [panduan Developer](docs/user-guide/developer.html#setup).

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
- [Verifikasi tampilan wizard setup awal di lima lebar layar](docs/setup-verification-2026-09-06/browser-results.json)
- [Hasil perbaikan dan tes ulang 6 September 2026](docs/fix-verification-2026-09-06/HASIL-TES.md)
- [Arsip audit sebelum perbaikan](docs/audit-2026-09-06/index.html)

## Catatan desain data

Semua data bisnis membawa `tenant_id`, sedangkan data operasional cabang juga membawa `store_id`. Produk, transaksi, pembelian, pengeluaran, member, tenant, cabang, dan pengguna memakai soft delete. Transaksi yang diarsipkan tidak mengembalikan stok otomatis agar jejak audit tidak berubah diam-diam.

Transaksi kasir masuk sebagai data riil. Akses non-riil mengikuti izin master role; default tersedia untuk Developer dan Superadmin. Persentase laporan mengikuti pengaturan masing-masing cabang. Akun tanpa izin tidak menerima kontrol non-riil dan permintaan laporannya dibatasi server.

Halaman laporan menyediakan ekspor Excel sesuai periode dan jenis laporan aktif. Workbook berisi tiga sheet: ringkasan dengan formula, rincian transaksi, dan rincian pengeluaran. Permintaan ekspor non-riil dari role pegawai otomatis dipaksa menjadi laporan riil.

Scan QR kamera menggunakan `BarcodeDetector` bawaan browser. Gunakan Chrome/Edge modern melalui HTTPS atau localhost agar izin kamera tersedia.

## Revisi 28 September 2026

| Poin | Fitur | Tempat |
|---|---|---|
| Export | SKU & barcode ditulis sebagai teks (nol di depan dan barcode 13 digit tidak rusak). Workbook produk memuat sheet *Harga Warung* dan *Pilihan Harga* yang ikut diimpor kembali. File Excel ditulis penuh sebelum diunduh sehingga unduhan tidak terpotong/rusak. Laporan menampilkan qty `× 2` (bukan `× 2.000`) dan seluruh pembayaran split. | Produk, Membership, Laporan, Inventaris |
| Icon | Ikon menu dan kategori (Bootstrap Icons atau emoji). Menu tanpa ikon memakai ikon kategori. | Produk → Edit / Kelola kategori; tampil di Kasir |
| SKU & harga | Satu SKU dapat memiliki beberapa pilihan harga (mis. porsi kecil/besar). Kasir memilih harga saat menu diklik; stok tetap satu. Pilihan harga dapat berlaku di semua warung atau satu warung. | Produk → tombol **Harga** |
| Menu consolidated | Harga normal/online dapat berbeda per warung (Warung A Rp6.000, Warung B Rp7.000) dan menu dapat disembunyikan di warung tertentu. Kosong = ikut harga default. | Produk → tombol **Harga** |
| Inventory | Modul **Inventaris** untuk peralatan (panci, kompor, freezer): jumlah baik/rusak, lokasi, nilai, riwayat perubahan, arsip, ekspor Excel. Terbuka untuk Head of Ops, Ops Admin, Outlet Manager, SPV, Superadmin, Developer. | Menu Inventaris |
| Stok / bahan baku | Kolom **Rusak / tidak layak** (input manual, mengurangi stok) pada tabel bahan baku (mentah) dan olahan (matang), plus tombol **Catat rusak** dengan pilihan stok mentah/matang. | Stok / Gudang |
| Tax & services | Service charge dan pajak (nama & tarif bebas, mis. PB1 10%) per cabang, dapat dibatasi per jenis pesanan. Service dihitung dari total setelah diskon; pajak dari total setelah diskon + service. Muncul di kasir, struk, laporan, dan ekspor; pajak dikeluarkan dari laba. | Pengaturan → Pajak & service |
| Printer kasir | Printer Epson ePOS (TM-m30, TM-T82III/X, TM-T88VI, dll.) mencetak struk customer + dapur langsung dari browser kasir lewat jaringan lokal, opsional membuka cash drawer. Bila gagal, struk browser dibuka sebagai cadangan. | Pengaturan → Perangkat terhubung |
| Membership | Tombol **Riwayat** per member: mutasi deposit, transaksi, ringkasan belanja, filter periode, ekspor Excel. Akun satu cabang hanya melihat aktivitas di cabangnya. | Membership, juga tautan di Kasir |

Bug yang diperbaiki pada revisi ini: harga di modal Edit produk terbaca 100× lipat (Rp15.000 tampil Rp1.500.000), verifikasi kartu member selalu menampilkan "Kartu tidak ditemukan", qty desimal tampil seperti ribuan di Ringkasan/Produk/Tutup kasir/Pembelian/Laporan, cabang nonaktif yang tersisa di session tetap dipakai untuk transaksi, impor Excel/CSV membaca "15.000" sebagai 15, dan daftar Transaksi pada mode consolidated hanya menampilkan satu cabang.

### Deploy revisi

1. Upload file yang berubah (tanpa npm; CSS/JS ada di `public/css/overrides.css`, `public/js/overrides.js`, `public/js/epos-printer.js`).
2. Login, lalu buka `/maintenance/migrate` dan `/maintenance/optimize-clear` (lihat RECOVERY.md). Migration `2026_09_28_000100` aman dijalankan ulang.

### Menyiapkan printer Epson ePOS

1. Beri printer IP tetap dan aktifkan **ePOS-Print** lewat EpsonNet Config / Web Config printer.
2. Pengaturan → Perangkat terhubung → Tambah: jenis *Printer struk*, cara cetak *Epson ePOS*, isi IP, lebar kertas, dan opsi cetak otomatis/lembar dapur/cash drawer. Tekan **Tes cetak**.
3. Bila aplikasi dibuka lewat HTTPS, aktifkan HTTPS di printer, centang *Printer memakai HTTPS*, lalu buka `https://IP-printer` sekali di browser kasir untuk menerima sertifikatnya. Browser memblokir pengiriman dari halaman HTTPS ke printer HTTP.

Tes tambahan: `php artisan test --filter=SeptemberRevisionTest` dan `node --test tests/js/epos-printer.test.cjs tests/js/pos-revision.test.cjs`.
