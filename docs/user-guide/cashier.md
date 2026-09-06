# User guide Kasir

Menjalankan operasional cabang. Minta otorisasi pejabat berwenang untuk transaksi pengganti, pembatalan lewat 30 detik, koreksi deposit, dan tutup kasir.

Cabang: Cabang akun. Pemegang otorisasi: tidak. Edit stok awal: tidak.

Ikuti cabang akun; gunakan sidebar desktop atau navigasi bawah mobile. Tombol Keluar mobile berada pada baris akun di bawah header. Screenshot menggunakan data demo setelah perbaikan.

## Kasir dan tutup kasir

Mencatat pesanan, menerima pembayaran, melanjutkan open bill, mencetak struk, dan melakukan rekonsiliasi kas harian.

![Kasir — Kasir dan tutup kasir](../fix-verification-2026-09-06/screenshots/role-cashier-kasir.png)

### Membuat pesanan

1. Pastikan nama cabang benar dan stok menu tersedia. Cari produk lewat nama, SKU/barcode, kategori, atau urutan harga/nama.
2. Klik produk untuk memasukkannya ke keranjang. Ubah jumlah pada kolom qty atau tombol +/−; periksa satuan pcs/gram.
3. Pilih Dine in dan isi nomor meja, Take away, atau Ojek online dan pilih platform. Harga online digunakan otomatis untuk pesanan online.
4. Pilih member dari daftar atau tombol scan jika diperlukan. Periksa nama, saldo, dan diskon sebelum meneruskan.
5. Periksa diskon Rp, diskon %, atau diskon member. Pastikan total dan jumlah produk benar.

### Membayar dan mencetak

1. Pilih Tunai, QRIS, Transfer, Debit, atau Deposit. Untuk QRIS/transfer/debit pilih bank atau provider penerima.
2. Tunai: masukkan uang yang benar-benar diterima. Contoh total Rp25.000, terima Rp30.000, maka kembalian Rp5.000. Input kosong atau kurang ditolak.
3. Deposit: pilih member. Anda dapat memakai seluruh tagihan atau sebagian saldo. Untuk split, isi deposit yang dipakai dan pilih kanal pelunasan; jika sisa dibayar tunai, isi nominal uang yang diterima.
4. Klik Bayar & cetak sekali. Tunggu proses selesai dan periksa struk. Stok berkurang dan saldo deposit terpakai dikurangi hanya ketika transaksi berhasil.
5. Jika struk belum tercetak, periksa Transaksi dan cetak ulang invoice yang sudah tersimpan sebelum membuat pesanan ulang.

### Menyimpan dan melanjutkan open bill

1. Susun pesanan lalu klik Pending. Bill tersimpan tanpa mengurangi stok.
2. Klik Open bill, pilih invoice, periksa lagi member, item, harga, dan stok.
3. Ubah pesanan lalu Pending untuk memperbarui bill yang sama; gunakan Bayar & cetak untuk menyelesaikannya.
4. Untuk membatalkan pending, gunakan tombol batal pada daftar Open bill dan isi alasan.

### Transaksi pengganti dan nominal custom

1. Retur/transaksi pengganti: centang opsi tersebut dan isi PIN Manager/SPV yang berwenang. Transaksi pengganti tidak menagih pembayaran, tetapi mengeluarkan stok barang pengganti.
2. Custom ON/OFF hanya dapat diatur oleh role pemegang otorisasi. Ketika diaktifkan, tombol Custom memungkinkan nama dan harga item manual yang tampil pada nota.

### Rekonsiliasi akhir hari

1. Klik Tutup kasir. Rekap berlaku untuk cabang dan tanggal hari ini.
2. Masukkan modal kas awal dan kas fisik yang dihitung; periksa penjualan tunai bersih, top up tunai, dan pengeluaran tunai.
3. Kas seharusnya = modal awal + penjualan tunai net + top up tunai − pengeluaran tunai. Isi catatan selisih/serah terima.
4. Jika akun bukan pemegang otorisasi, isi PIN Manager/SPV yang berwenang untuk cabang aktif. Klik Simpan tutup kasir dan periksa selisih.
5. Catatan tutup kasir yang sudah tersimpan hanya dapat diperbarui oleh pemegang otorisasi. Gunakan Cetak rekap bila diperlukan.

Hasil: Invoice selesai tercatat bersama pembayaran; struk customer dan salinan dapur tersedia. Tutup kasir menyimpan rekonsiliasi harian, bukan mengunci seluruh transaksi berikutnya.

- PIN hanya diterima dari akun aktif yang berwenang atas cabang tersebut.
- Kamera dan printer perlu dicoba di perangkat operasional; panduan ini tidak menggantikan uji fisik.

## Transaksi

Menelusuri invoice, mencetak ulang struk, dan membatalkan transaksi dengan jejak audit.

![Kasir — Transaksi](../fix-verification-2026-09-06/screenshots/role-cashier-transaksi.png)

### Menemukan dan mencetak invoice

1. Buka Transaksi. Pilih Semua, Pending, atau Dibatalkan sesuai kebutuhan.
2. Cari invoice pada halaman aktif; gunakan paginasi untuk melihat catatan pada halaman lain. Pencarian di tabel ini memfilter baris halaman yang sedang ditampilkan.
3. Periksa waktu, pesanan, kasir/member, metode pembayaran, total, dan status.
4. Pada transaksi Selesai, klik ikon printer untuk membuka struk. Transaksi baru tersedia hanya jika akun juga memiliki menu Kasir.

### Membatalkan transaksi selesai

1. Klik ikon pembatalan pada invoice yang benar.
2. Isi alasan yang jelas, minimal lima karakter. Jika transaksi sudah lewat 30 detik, isi PIN Manager/SPV yang berwenang.
3. Konfirmasi pembatalan, lalu pastikan status menjadi Dibatalkan.
4. Periksa pemulihan stok dan deposit terkait. Invoice tetap tersimpan bersama alasan dan pemberi otorisasi. Pengembalian uang tunai/non-deposit di luar aplikasi tetap perlu diselesaikan secara operasional.

Hasil: Pembatalan mengubah status dan mencatat audit; transaksi tidak dihapus permanen.

- Untuk melanjutkan atau membatalkan bill Pending, gunakan Open bill di Kasir.
- Nominal pembayaran tunai pada riwayat dapat mencakup uang diterima sebelum kembalian; periksa struk untuk rincian kembalian.

## Stok / Gudang

Mencatat pemakaian bahan, produksi, konsumsi, proses ulang, penyesuaian, serta opname.

![Kasir — Stok / Gudang](../fix-verification-2026-09-06/screenshots/role-cashier-gudang.png)

### Membaca dan mengedit tabel

1. Buka bagian bahan baku atau olahan. Periksa nama produk, satuan, stok awal, pergerakan, dan sisa. Tabel dapat digeser mendatar pada layar kecil.
2. Kolom Stok terpakai bahan baku serta Keterangan dapat diedit langsung. Tunggu indikator penyimpanan selesai setelah mengganti nilai.
3. Stok awal hanya dapat dikoreksi Developer, Superadmin, Head of Ops, atau Ops Admin. Kolom perhitungan lain mengikuti pergerakan, bukan diisi ulang secara manual.

### Produksi

1. Klik Catat produksi. Pilih bahan baku dan jumlah terolah.
2. Pilih menu hasil, masukkan tambahan olahan dan keterangan, lalu Simpan produksi.
3. Periksa bahan berkurang dan menu hasil bertambah. Jumlah bahan melebihi stok ditolak.

### Proses ulang

1. Klik Proses ulang. Pilih olahan sumber dan olahan tujuan yang berbeda.
2. Isi qty sumber, qty hasil, dan keterangan wajib; simpan.
3. Periksa pengurangan sumber dan penambahan hasil pada stok serta riwayat pergerakan.

### Penyesuaian dan konsumsi

1. Klik Penyesuaian dan pilih produk.
2. Pilih Tambah stok, Kurangi stok, atau Konsumsi owner/karyawan. Isi qty dan alasan.
3. Simpan, lalu periksa sisa stok dan pergerakan. Pengurangan yang membuat stok negatif ditolak.

### Opname akhir hari

1. Hitung sisa fisik per produk dan satuan.
2. Klik Stock opname, pilih produk, isi sisa fisik serta catatan, lalu simpan.
3. Saldo sistem disesuaikan ke jumlah fisik; selisih dicatat sebagai mutasi. Sisa olahan dibawa ke stok hari berikutnya.

Hasil: Stok bahan dan olahan berubah sesuai jenis aktivitas, dengan catatan pergerakan.

- Catat pemakaian satu kali pada aktivitas yang sesuai. Bahan yang sudah berkurang melalui Produksi tidak perlu dikurangi lagi sebagai pemakaian manual untuk aktivitas yang sama.
- Gunakan catatan untuk menjelaskan selisih fisik atau koreksi.

## Pengeluaran

Mencatat biaya operasional dan kanal pembayarannya agar rekap kas dan laporan sesuai.

![Kasir — Pengeluaran](../fix-verification-2026-09-06/screenshots/role-cashier-pengeluaran.png)

### Mencatat biaya

1. Klik Catat pengeluaran. Pilih kategori yang tersedia dan tanggal kejadian.
2. Isi keterangan, pilih Dibayar melalui, lalu masukkan nominal minimal Rp1.
3. Simpan dan periksa baris baru, tanggal, nominal, serta kanal pembayaran.
4. Pengeluaran Tunai memengaruhi rekonsiliasi kas tunai; pilih kanal sesuai pembayaran sebenarnya.

### Mengarsipkan catatan salah

1. Temukan catatan yang salah, klik Arsipkan, lalu konfirmasi.
2. Jika perlu pengganti, catat pengeluaran yang benar dengan keterangan yang jelas. Menu ini belum menyediakan edit atau pemulihan arsip.

Hasil: Pengeluaran masuk ke laporan cabang sesuai tanggal dan metode pembayaran.

- Pastikan tidak menggandakan catatan biaya yang sudah dimasukkan.

## Membership dan deposit

Mengaktifkan kartu, mengelola data member, menerima top up, dan mengoreksi deposit dengan otorisasi.

![Kasir — Membership dan deposit](../fix-verification-2026-09-06/screenshots/role-cashier-member.png)

### Aktivasi kartu baru

1. Siapkan kartu pra-cetak dari Pengaturan; mintakan kepada admin jika akun tidak memiliki akses pembuatan kartu.
2. Klik Scan kartu & daftar. Scan QR atau masukkan kode kartu lalu Verifikasi.
3. Setelah kartu kosong ditemukan, isi nama lengkap, kontak, domisili, tanggal lahir, dan diskon sesuai kebutuhan.
4. Klik Aktifkan kartu & simpan member. Kartu terhubung ke member dan tidak tersedia lagi sebagai kartu kosong.

### Mencari dan memperbarui member

1. Masukkan nama/kode/QR pada pencarian lalu Enter, atau gunakan Cari member dan scan/manual kode.
2. Periksa identitas serta status sebelum transaksi. Gunakan ikon pensil untuk memperbarui data dan diskon.
3. Gunakan ikon kartu untuk membuka/cetak kartu; saldo tidak ditampilkan pada desain kartu.
4. Nonaktifkan jika member tidak boleh digunakan di kasir; Aktifkan untuk mengembalikannya.

### Top up

1. Pada member aktif, klik Top up. Periksa nama penerima.
2. Masukkan nominal minimal Rp1.000 dan metode penerimaan yang tersedia, kemudian simpan.
3. Periksa saldo baru. Saldo member dapat dipakai dari cabang lain dalam tenant yang sama.

### Koreksi deposit

1. Klik ikon koreksi (panah dua arah). Pilih tambah/kredit atau kurang/debit, nominal, dan alasan minimal lima karakter.
2. Akun non-supervisor harus memasukkan PIN Manager/SPV yang berwenang; pemegang otorisasi dapat menggunakan otorisasinya sendiri.
3. Simpan dan periksa saldo. Debit melebihi saldo ditolak; koreksi disimpan dengan catatan otorisasi.

### Ekspor

1. Klik Download Excel untuk mengunduh data member yang disediakan sistem. Simpan file sesuai pengelolaan data internal.

Hasil: Identitas, status dan saldo member tersedia sesuai tenant; aktivitas deposit tercatat.

- Pendaftaran membutuhkan kartu kosong terverifikasi. Kartu yang sudah dipakai tidak dapat diaktivasi ulang untuk orang lain.
- Gunakan Top up untuk penerimaan uang baru, dan Koreksi deposit untuk pembetulan beralasan.

