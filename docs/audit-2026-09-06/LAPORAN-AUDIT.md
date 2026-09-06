# Audit flow, hak akses, tablet & mobile

Audit lokal 6 September 2026 · commit c146e56 · Laravel/PHP + MySQL · Microsoft Edge headless. Ditemukan 7 bug/miss flow: 2 prioritas tinggi (P1) dan 5 prioritas menengah (P2). User guide final ditunda sesuai syarat pengguna karena masih ada bug terbuka. Kode aplikasi belum diubah.

## Validasi dan batas pemeriksaan

113 tes backend lulus (633 assertion), 4 tes JavaScript struk lulus. Pemeriksaan browser: 11 halaman × 5 viewport = 55 render (1440, 1024, 768, 390, 360 px), seluruhnya HTTP 200 pada MySQL tanpa overflow horizontal dokumen. Tabel lebar menggulir di dalam wadah. Matriks 8 role × 10 URL = 80 pemeriksaan akses; tujuh role bawaan sesuai aturan. Sepuluh modal di 390 px tidak melampaui lebar kartu; tidak ada pageerror pada pemeriksaan render/modal. Bukti screenshot menggunakan data demo/audit, bukan data produksi.

Cakupan ini merupakan audit kode, tes regresi yang tersedia, render, dan skenario browser terarah; bukan jaminan semua kombinasi alur bebas bug. Belum diuji pada perangkat iOS/Android fisik, Safari, kamera/QR nyata, printer Bluetooth/USB, cash drawer, gangguan jaringan, maupun seluruh variasi impor Excel. Uji concurrency tersedia di repository tetapi tidak dijalankan pada audit ini. Percobaan awal SQLite menghasilkan perbedaan perilaku kolom tanggal; seluruh hasil render final diulang pada MySQL yang digunakan aplikasi. Database utama dan .env tidak diubah; pengujian memakai warungkita_test serta schema terpisah warungkita_audit_20260906.

## Temuan

### B01 · P1 · Tunai kurang otomatis dianggap lunas

- Terdampak: Semua role dengan menu Kasir
- Reproduksi: Pilih Ayam Kampung Bakar Rp25.000, pilih Take away, isi Uang diterima Rp1.000, lalu Bayar & cetak.
- Aktual: Request browser mengirim payments[0].amount = 25000 dan mendapat HTTP 200. Database mencatat total, paid_amount, dan pembayaran tunai Rp25.000; status completed. Angka Rp1.000 yang diketik hilang.
- Harapan: Nominal tunai dikirim sesuai input dan checkout ditolak jika pembayaran kurang.
- Penyebab: orderPayload() memakai Math.max(remaining, moneyValue(...)), sehingga validasi nominal kurang di backend tidak pernah menerima angka asli.
- Usulan: Kirim angka tunai asli; validasi kekurangan di UI dan server. Uji uang kosong, kurang, pas, lebih, serta split deposit/tunai.
- Sumber: resources/views/pos/index.blade.php:89
- Screenshot: [cash-underpayment-before.png](screenshots/cash-underpayment-before.png), [cash-underpayment-saved.png](screenshots/cash-underpayment-saved.png)

### B02 · P1 · Edit langsung DP mengubah Rp5.000 menjadi Rp5

- Terdampak: Developer, Superadmin, Head of Ops, Ops Admin, dan role khusus Pembelian
- Reproduksi: Di Pembelian, ubah pembayaran PO-AUDIT-USED menjadi DP. Ketik 5000 pada nominal DP lalu pindah fokus.
- Aktual: Formatter menampilkan 5.000, tetapi form.submit() mengirimnya tanpa normalisasi. Setelah reload field berisi 5; database dp_amount = 5.00, bukan 5000.00.
- Harapan: Nominal yang disimpan tetap Rp5.000.
- Penyebab: this.form.submit() melewati event submit yang membersihkan pemisah ribuan pada public/js/overrides.js. String 5.000 dibaca server sebagai angka desimal 5.
- Usulan: Normalisasi sebelum autosubmit atau gunakan requestSubmit(); uji 1.000, 5.000, 1.000.000 dan perpindahan status.
- Sumber: resources/views/purchases/index.blade.php:6; public/js/overrides.js:62
- Screenshot: [purchase-dp-after.png](screenshots/purchase-dp-after.png)

### B03 · P2 · Stok kemarin terlihat habis sampai Gudang dibuka

- Terdampak: Semua role Kasir; stok harian produk juga perlu diperiksa
- Reproduksi: Sediakan menu dengan sisa kemarin 7 pcs dan tanpa baris stok hari ini. Buka Kasir lebih dulu, kemudian Gudang, lalu kembali ke Kasir.
- Aktual: Kasir pertama menampilkan Habis dan produk tidak bisa ditambah. Setelah Gudang dibuka, stok menjadi 7 pcs tanpa transaksi penerimaan atau produksi.
- Harapan: Kasir langsung menggunakan sisa stok sesuai aturan carryover; tidak bergantung pada menu yang dibuka lebih dulu.
- Penyebab: pos() hanya membaca dailyStocks hari ini. Inisialisasi carryover menuStockForDate() dilakukan oleh inventory(), bukan pos().
- Usulan: Samakan sumber stok efektif di Kasir, Produk, Ringkasan, dan Gudang. Uji hari baru serta cabang baru tanpa kunjungan Gudang.
- Sumber: app/Http/Controllers/WarungController.php:323; app/Http/Controllers/WarungController.php:837
- Screenshot: [day-stock-before.png](screenshots/day-stock-before.png), [day-stock-after.png](screenshots/day-stock-after.png)

### B04 · P2 · Tombol Keluar hilang di mobile dan tablet portrait

- Terdampak: Semua role pada lebar layar ≤820 px
- Reproduksi: Login, lalu buka halaman pada viewport 768, 390, atau 360 px. Cari tombol Keluar di navigasi atas/bawah.
- Aktual: Satu-satunya tombol logout berada dalam sidebar dengan display:none. Browser mengonfirmasi logoutVisible=false di seluruh 11 halaman pada ketiga ukuran tersebut.
- Harapan: Pengguna tetap dapat keluar atau mengganti akun pada perangkat bersama.
- Penyebab: Mobile-nav hanya merender tautan menu; tidak ada form logout pengganti.
- Usulan: Tambahkan menu akun/Keluar yang dapat diakses pada navigasi mobile. Gunakan POST dan CSRF seperti logout desktop.
- Sumber: resources/views/layouts/app.blade.php:43; resources/views/layouts/app.blade.php:91; resources/css/app.css:302
- Screenshot: [390-kasir.png](screenshots/390-kasir.png), [role-cashier-kasir.png](screenshots/role-cashier-kasir.png)

### B05 · P2 · Koreksi nama supplier ditolak setelah stok dipakai

- Terdampak: Role dengan menu Pembelian
- Reproduksi: Gunakan pembelian diterima 10 pcs dengan stok tersisa 2 pcs. PUT koreksi hanya nama supplier/catatan; produk, qty, harga, dan status penerimaan tetap.
- Aktual: HTTP 422: Status tidak dapat diubah karena stok sudah terpakai. Perubahan metadata yang tidak memengaruhi stok ikut ditolak.
- Harapan: Nama supplier/catatan dapat dikoreksi tanpa membalik seluruh penerimaan stok.
- Penyebab: updatePurchase() selalu membalik 10 pcs melalui applyPurchaseStock(...,-1) sebelum menerapkan data baru. Saldo 2 pcs membuat pembalikan gagal.
- Usulan: Pisahkan perubahan metadata dari perubahan persediaan; gunakan perubahan stok bersih untuk produk yang sama. Pertahankan larangan hasil stok negatif.
- Sumber: app/Http/Controllers/WarungController.php:1136; app/Http/Controllers/WarungController.php:1168
- Screenshot: [purchase-dp-after.png](screenshots/purchase-dp-after.png)

### B06 · P2 · Role khusus Transaksi mendapat tombol menuju halaman 403

- Terdampak: Role khusus yang memiliki Transaksi tanpa Kasir
- Reproduksi: Buat role dengan modules=[transactions], login, lalu klik Transaksi baru pada halaman Transaksi.
- Aktual: Halaman Transaksi dapat dibuka (200) dan tombol terlihat. Tombol mengarah ke /kasir yang berakhir 403. Backend membatasi akses dengan benar, tetapi alur UI buntu.
- Harapan: Tombol hanya muncul jika pengguna memiliki akses Kasir.
- Penyebab: Tautan Transaksi baru tidak dibungkus canAccess(pos). Tautan Ringkasan ke Gudang/Transaksi juga perlu pemeriksaan serupa untuk role khusus.
- Usulan: Terapkan pemeriksaan izin pada semua tautan antar-modul, lalu uji role dengan satu menu.
- Sumber: resources/views/transactions/index.blade.php:4
- Screenshot: [role-transactions_only-transaksi.png](screenshots/role-transactions_only-transaksi.png)

### B07 · P2 · Ganti laporan riil/non-riil menghapus rentang tanggal

- Terdampak: Role dengan Laporan dan izin non-riil
- Reproduksi: Pilih periode custom 1–15 Agustus 2026 pada laporan riil, lalu klik Non-riil.
- Aktual: URL baru hanya memuat type=non_real&period=custom. Tanggal berubah menjadi 1–6 September 2026 pada hari audit. Pengguna membandingkan periode berbeda tanpa memilih ulang tanggal.
- Harapan: Tanggal awal dan akhir tetap 1–15 Agustus ketika jenis laporan berubah.
- Penyebab: Tautan tab hanya meneruskan type dan period; reportRange() mengisi default ketika from/to hilang.
- Usulan: Pertahankan from dan to pada kedua tautan tab; uji perpindahan dua arah dan ekspor sesudahnya.
- Sumber: resources/views/reports/index.blade.php:4; app/Http/Controllers/WarungController.php:143
- Screenshot: [report-period-before.png](screenshots/report-period-before.png), [report-period-after.png](screenshots/report-period-after.png)

## Cakupan per menu

| Menu | Alur diperiksa | Hasil / batas |
|---|---|---|
| Ringkasan | KPI, tren, stok rendah, transaksi terbaru, cabang/consolidated | Tes backend dan render 5 viewport lulus; dependensi carryover stok dan tautan role khusus perlu diperbaiki bersama B03/B06. |
| Kasir + Tutup kasir | Dine in/takeaway/online, pending, pembayaran, split deposit, retur, void, struk, rekonsiliasi/PIN | Tes yang tersedia lulus; B01 dan B03 ditemukan pada alur browser. |
| Transaksi | Daftar/status, cetak, pembatalan dan PIN, pembatasan cabang | Tes backend lulus; B06 pada role khusus. Pencarian saat ini hanya memfilter 20 baris halaman aktif. |
| Produk | Tambah/edit, kategori, arsip/pulihkan, impor/ekspor, harga normal/online | Render dan modal mobile diperiksa; tes soft delete/restore lulus. Pencarian hanya halaman aktif; variasi file impor belum diuji menyeluruh. |
| Stok / Gudang | Stok awal, penggunaan, penyesuaian, produksi, proses ulang, opname | Tes flow dan akses stok awal lulus; B03 menunjukkan ketergantungan inisialisasi antar-menu. |
| Pembelian | Penerimaan, lunas/DP/belum bayar, koreksi, dampak stok | B02 dan B05 terkonfirmasi. Tes lama belum meliputi autosubmit nominal terformat dan koreksi metadata setelah pemakaian. |
| Pengeluaran | Kategori tetap, kanal pembayaran, tambah/arsip, dampak laporan/kas | Tes kategori, isolasi cabang dan rekonsiliasi lulus; halaman dan modal mobile diperiksa. Tidak ada temuan tambahan terkonfirmasi. |
| Membership | Kartu pra-cetak, aktivasi, cari member, edit/status, top up, koreksi deposit, lintas cabang | Tes lintas cabang/tenant, saldo dan PIN lulus; halaman dan modal mobile diperiksa. Scan kamera fisik belum diuji. |
| Laporan | Periode, riil/non-riil, consolidated, Excel, rincian dan struk | Tes perhitungan dan pembatasan akses lulus; B07 terkonfirmasi. |
| Pengaturan | Aturan bisnis, identitas, struk, perangkat, kartu, role, akun, cabang, pemeliharaan | Tes sub-permission, role dan isolasi lulus; halaman/modal diperiksa. Uji perangkat hanya mencatat validitas konfigurasi, bukan bukti koneksi fisik. |

## Hak akses

Akses di tabel adalah default pada data audit; master role dapat mengubahnya. Developer/Superadmin/Head of Ops dapat lintas cabang. Role lainnya terikat cabang akun. Izin non-riil default hanya Developer/Superadmin. Pemegang otorisasi: Developer, Superadmin, Head of Ops, Outlet Manager, SPV. Koreksi stok awal: Developer, Superadmin, Head of Ops, Ops Admin. Pengaturan memiliki sub-izin tersendiri; pengelolaan role/akun tetap untuk Developer/Superadmin. Pemeliharaan khusus Developer, dengan akses transisi Superadmin ketika belum ada Developer aktif. Membership berlaku lintas cabang dalam tenant yang sama.

| Role | Menu yang dapat dibuka | Landing |
|---|---|---|
| Developer | Ringkasan, Kasir, Transaksi, Produk, Stok / Gudang, Pembelian, Pengeluaran, Membership, Laporan, Pengaturan | /dashboard |
| Superadmin | Ringkasan, Kasir, Transaksi, Produk, Stok / Gudang, Pembelian, Pengeluaran, Membership, Laporan, Pengaturan | /dashboard |
| Head of Ops | Ringkasan, Kasir, Transaksi, Produk, Stok / Gudang, Pembelian, Pengeluaran, Membership, Laporan | /dashboard |
| Ops Admin | Kasir, Transaksi, Produk, Stok / Gudang, Pembelian, Pengeluaran, Membership | /kasir |
| Outlet Manager | Kasir, Transaksi, Stok / Gudang, Pengeluaran, Membership | /kasir |
| SPV | Kasir, Transaksi, Stok / Gudang, Pengeluaran, Membership | /kasir |
| Kasir | Kasir, Transaksi, Stok / Gudang, Pengeluaran, Membership | /kasir |
| Role khusus: Transaksi saja | Transaksi | /transaksi |

## Catatan tambahan

- README masih memuat role Owner/Admin/Gudang dan akun lama yang tidak cocok dengan master/seeder saat ini; perbarui sebelum penerbitan panduan.
- Label “tanpa omzet” saat ini berarti tanpa Ringkasan/Laporan; role operasional tetap dapat melihat nominal transaksi dan rekap tutup kasir sesuai modulnya. Jika dimaksudkan sama sekali tidak boleh melihat total penjualan, aturan bisnis perlu ditegaskan.
- Login menampilkan kredensial demo secara tetap. Sebelum pemakaian operasional, pisahkan tampilan/akun demo dari instalasi operasional. Audit ini tidak menyimpulkan bahwa server produksi memakai kredensial tersebut.
- Bukti B05 diperoleh lewat request terautentikasi dengan payload form, bukan klik simpan modal. Modal lain hanya diperiksa rendernya, kecuali aksi yang disebutkan eksplisit.

## Syarat sebelum user guide final

Perbaiki B01–B07, tambahkan tes regresi untuk skenario temuan, lalu ulangi flow pembayaran, nominal DP, pergantian hari, logout mobile, koreksi pembelian, role khusus, dan filter laporan. Setelah lolos, panduan dapat dibagi per 7 role dengan daftar menu, prasyarat, langkah, hasil, penanganan kesalahan, dan screenshot desktop/tablet/mobile dari versi yang sudah diperbaiki.

## Bukti terstruktur

- [Render browser](browser-results.json)
- [Skenario dan matriks role](scenario-results.json)
- [Modal dan periode laporan](extra-results.json)
- [Nilai tersimpan di database](database-evidence.json)
- [Laporan visual dan galeri screenshot](index.html)
