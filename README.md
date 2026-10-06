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

### Printer Xantri BT-58D Pro melalui RawBT (Android)

1. Pasang [RawBT](https://play.google.com/store/apps/details?id=ru.a402d.rawbtprinter) di tablet Android. Pairing Bluetooth printer, pilih printer di RawBT, lalu lakukan tes cetak.
2. Pengaturan → Perangkat terhubung → Tambah: jenis **Printer struk**, cara cetak **RawBT · Bluetooth Android, 58 mm**, pilih cabang dan simpan. Buka **Ubah perangkat** untuk mengakses **Tes cetak RawBT**.
3. Setelah pembayaran di Android, halaman struk terbuka. Pilih **Customer + dapur**, **Customer saja**, atau **Dapur saja**, lalu ketuk **Cetak struk**. RawBT dipilih otomatis di Android. Cara cetak browser/Epson tersedia di **Pilihan printer & bantuan**. Pemanggilan RawBT membutuhkan ketukan pengguna agar browser mengizinkan aplikasi eksternal dibuka.
4. Kertas 58 mm, Font A 32 karakter per baris. Pemotongan kertas manual. Website mengirim perintah ESC/POS melalui Android intent; hasil fisik cetak tidak dapat dikonfirmasi website. Periksa kertas sebelum mengulang cetak. RawBT tidak tersedia di iPad; perangkat selain Android tetap memakai alur cetak browser/Epson yang tersedia.
5. Logo branding dicetak hitam putih pada salinan customer jika **Tampilkan logo di struk** aktif. Konversi gambar membutuhkan GD PHP di server. Logo dibatasi 128 × 96 titik dengan proporsi asli; transparansi menjadi putih. Bila file logo hilang atau GD tidak tersedia, halaman menampilkan penyebabnya dan struk tetap dapat dicetak tanpa logo.

### Pengaturan Epson ePOS

1. Beri printer IP tetap dan aktifkan **ePOS-Print** lewat EpsonNet Config / Web Config printer.
2. Pengaturan → Perangkat terhubung → Tambah: jenis *Printer struk*, cara cetak *Epson ePOS*, isi IP, lebar kertas, dan opsi cetak otomatis/lembar dapur/cash drawer. Tekan **Tes cetak**.
3. Bila aplikasi dibuka lewat HTTPS, aktifkan HTTPS di printer, centang *Printer memakai HTTPS*, lalu buka `https://IP-printer` sekali di browser kasir untuk menerima sertifikatnya. Browser memblokir pengiriman dari halaman HTTPS ke printer HTTP.

Tes tambahan: `php artisan test --filter=SeptemberRevisionTest` dan `node --test tests/js/epos-printer.test.cjs tests/js/pos-revision.test.cjs`.

## Revisi 1 Oktober 2026

| Poin | Fitur | Tempat |
|---|---|---|
| Login tiap akun | Bagian **Login** di setiap user guide: halaman pertama per role (Ringkasan untuk Developer/Superadmin/Head of Ops, Kasir untuk role lain), menu, PIN otorisasi, kendala login, dan cara Superadmin membuat akun staf. | `docs/user-guide` |
| Reservasi | Modul **Reservasi** (nama, HP, tanggal/jam, jumlah orang, meja, catatan, DP tunai/QRIS/transfer/debit, status Dipesan/Tamu datang/Selesai/Batal/Tidak datang). Di Kasir tombol **Reservasi** mengisi meja dan memotong DP dari tagihan (pembayaran bermetode `dp`). DP tunai masuk tutup kasir pada hari diterima; DP yang dikembalikan mengurangi kas. Pembatalan transaksi membuka kembali DP. | Menu Reservasi, Kasir, Tutup kasir |
| Upload menu & stok | Produk → Import Excel menerima workbook outlet apa adanya: sheet MATANG (menu, NOMINAL OFLINE/ONLINE), MENTAH/SUPPORT/CV (bahan baku), atau file *Stok Bahan Baku*, *Stok Olahan Hari Ini*, *Stok CV*. Menu yang berbagi SKU bahan (mis. BA-04) mendapat SKU BA-04-1, BA-04-2, …; SKU asal disimpan di `ingredient_sku`. Kolom STOK opsional menyetel stok cabang aktif sebagai stock opname (`IMPORT-tanggal`). Impor ulang memperbarui tanpa menggandakan. Pencarian Produk kini di server (nama/SKU/barcode) dengan filter jenis & kategori. | Produk |
| Upload file & template | Semua input file (Import Excel, logo) memakai kotak klik/seret: nama & ukuran file, pratinjau gambar, validasi jenis/ukuran, dan tombol *Mengunggah…* agar tidak terkirim dua kali. Modal Import menampilkan kolom wajib per sheet untuk dua format (workbook outlet & standar) dan tombol **Download template** (`/produk/import/template/{outlet|standard}`, berisi sheet *Petunjuk* yang diabaikan saat impor). Definisi kolom ada di `App\Support\ImportTemplate`. | Produk, Pengaturan |
| Tutorial printer | Bagian **Printer** di setiap user guide: printer USB/Bluetooth lewat dialog cetak browser dan Epson ePOS lewat jaringan, termasuk penanganan gagal cetak. | `docs/user-guide` |

SKU produk kini unik per jenis (menu / bahan baku), sehingga menu dan bahan baku boleh memakai SKU yang sama. Role bawaan yang memegang Kasir otomatis mendapat modul Reservasi; Superadmin dapat mengubahnya di master role.

Deploy: upload file yang berubah, lalu jalankan `/maintenance/migrate` (migration `2026_10_01_000100` aman dijalankan ulang) dan `/maintenance/optimize-clear`.

Tes tambahan: `php artisan test --filter=OctoberRevisionTest`. Screenshot panduan dibuat ulang dengan `WARUNG_BASE_URL=... node tests/browser/october-guide.cjs` terhadap schema review terpisah, lalu `node docs/user-guide/build-guide.cjs` dan `node docs/user-guide/export-pdf.cjs`.
