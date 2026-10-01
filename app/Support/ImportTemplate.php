<?php

namespace App\Support;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Definisi template impor produk. Satu sumber untuk tabel panduan di modal Import
 * dan untuk file template .xlsx yang diunduh, sehingga keduanya selalu sama.
 *
 * Status kolom: 'wajib', 'menu' (wajib untuk menu), atau 'opsional'.
 */
final class ImportTemplate
{
    private const INGREDIENT_COLUMNS = [
        ['NO', 'opsional', 'Nomor urut; diabaikan saat impor.', '1'],
        ['NAMA PRODUK', 'wajib', 'Nama bahan. Judul kolom BAHAN BAKU atau ITEM juga diterima.', 'Ayam Karkas (20 potong / pack)'],
        ['SATUAN', 'opsional', 'Satuan stok. Kosong = pcs.', 'pcs'],
        ['KATEGORI', 'opsional', 'Dibuat otomatis bila belum ada.', 'BASAH'],
        ['SKU', 'wajib', 'Kode unik bahan baku. Menjadi kunci saat impor ulang.', 'BA-04'],
        ['BARCODE', 'opsional', 'Kode barcode kemasan.', ''],
        ['MIN STOK', 'opsional', 'Batas stok aman. Sel kosong tidak menghapus nilai lama. MIN STOCK juga diterima.', '40'],
        ['STOK', 'opsional', 'Jumlah fisik di cabang aktif. Kosong = stok tidak diubah.', '120'],
    ];

    public const FORMATS = [
        'outlet' => [
            'label' => 'Workbook outlet',
            'badge' => 'Disarankan',
            'filename' => 'template-import-workbook-outlet.xlsx',
            'summary' => 'Format file dari tim pusat (POS MENU ALL OUTLET & SKU, Stok Bahan Baku, Stok Olahan Hari Ini, Stok CV). Nama sheet menentukan jenis produk: MATANG = menu siap jual; MENTAH, SUPPORT, dan CV = bahan baku.',
            'sheets' => [
                ['name' => 'MATANG', 'title' => 'Sheet MATANG · menu siap jual', 'columns' => [
                    ['NO', 'opsional', 'Nomor urut; diabaikan saat impor.', '4'],
                    ['NAMA PRODUK', 'wajib', 'Nama menu yang tampil di Kasir dan struk.', 'Ayam Bakar Negeri Size 1'],
                    ['SATUAN', 'opsional', 'Satuan jual. Kosong = pcs.', 'pcs'],
                    ['KATEGORI', 'opsional', 'Tombol kategori di Kasir; dibuat otomatis.', 'MAKANAN'],
                    ['SKU', 'wajib', 'SKU bahan/stok asal. Boleh sama untuk beberapa menu; sistem memberi BA-04-1, BA-04-2, dst.', 'BA-04'],
                    ['BARCODE', 'opsional', 'Untuk scan di Kasir.', ''],
                    ['NOMINAL OFLINE', 'wajib', 'Harga normal (dine in / take away). 15000, 15.000, dan Rp15.000 dibaca sama.', '12000'],
                    ['NOMINAL ONLINE', 'opsional', 'Harga ojek online, dibulatkan ke rupiah. Kosong = sama dengan harga normal.', '17143'],
                    ['MIN STOK', 'opsional', 'Batas stok aman olahan.', '20'],
                    ['STOK', 'opsional', 'Stok olahan hari ini di cabang aktif. Kosong = stok tidak diubah.', '25'],
                ]],
                ['name' => 'MENTAH', 'title' => 'Sheet MENTAH, SUPPORT, CV · bahan baku', 'columns' => self::INGREDIENT_COLUMNS],
                ['name' => 'SUPPORT', 'columns' => self::INGREDIENT_COLUMNS],
                ['name' => 'CV', 'columns' => self::INGREDIENT_COLUMNS],
            ],
            'rules' => [
                'Baris pertama berisi judul kolom. Urutan kolom dan huruf besar/kecil bebas; baris kosong dilewati.',
                'Boleh satu file berisi beberapa sheet, atau satu file per jenis. Sheet dengan nama lain dianggap menu bila memiliki kolom NOMINAL OFLINE, selain itu bahan baku.',
                'Impor ulang memperbarui data yang sama (bahan baku dicocokkan lewat SKU, menu lewat SKU + nama) tanpa menggandakan.',
                'Menu, harga, dan kategori berlaku untuk semua cabang. Kolom STOK hanya mengisi cabang yang sedang aktif dan tercatat sebagai stock opname.',
            ],
        ],
        'standard' => [
            'label' => 'Format standar',
            'badge' => 'Sama dengan Download Excel',
            'filename' => 'template-import-produk.xlsx',
            'summary' => 'Format hasil tombol Download Excel. Cocok untuk mengubah data yang sudah ada: unduh, ubah di Excel, lalu impor kembali.',
            'sheets' => [
                ['name' => 'Produk', 'title' => 'Sheet Produk', 'columns' => [
                    ['SKU', 'wajib', 'Kode unik per jenis produk. Menjadi kunci saat impor ulang.', 'ES-TEH-01'],
                    ['Barcode', 'opsional', 'Kode barcode untuk scan di Kasir.', ''],
                    ['Nama', 'wajib', 'Nama produk.', 'Es Teh Manis'],
                    ['Jenis', 'opsional', 'menu (siap jual) atau ingredient (bahan baku). Kosong = menu.', 'menu'],
                    ['Kategori', 'opsional', 'Dibuat otomatis bila belum ada.', 'Minuman'],
                    ['Satuan', 'opsional', 'Kosong = pcs.', 'gelas'],
                    ['Harga Beli', 'opsional', 'HPP per satuan untuk laporan laba.', '2000'],
                    ['Harga Normal', 'menu', 'Harga jual normal.', '6000'],
                    ['Harga Online', 'opsional', 'Kosong = sama dengan harga normal.', '8000'],
                    ['Stok Minimum', 'opsional', 'Batas stok aman.', '10'],
                    ['Ikon', 'opsional', 'Kode Bootstrap Icons dari daftar ikon (mis. bi-cup-straw) atau satu emoji.', 'bi-cup-straw'],
                    ['Stok Awal', 'opsional', 'Stok awal cabang aktif, hanya untuk produk yang belum punya stok hari ini.', '30'],
                ]],
                ['name' => 'Harga Warung', 'title' => 'Sheet Harga Warung (opsional) · harga berbeda per cabang', 'columns' => [
                    ['SKU', 'wajib', 'SKU menu yang sudah ada.', 'ES-TEH-01'],
                    ['Nama Produk', 'opsional', 'Hanya sebagai keterangan.', 'Es Teh Manis'],
                    ['Kode Warung', 'wajib', 'Kode cabang (lihat Pengaturan → Cabang).', 'WB'],
                    ['Nama Warung', 'opsional', 'Hanya sebagai keterangan.', 'Warung B'],
                    ['Dijual', 'opsional', 'Ya atau Tidak. Tidak = menu disembunyikan di cabang itu.', 'Ya'],
                    ['Harga Normal', 'opsional', 'Kosong = ikut harga default.', '7000'],
                    ['Harga Online', 'opsional', 'Kosong = ikut harga default.', ''],
                ]],
                ['name' => 'Pilihan Harga', 'title' => 'Sheet Pilihan Harga (opsional) · satu SKU banyak harga', 'columns' => [
                    ['SKU', 'wajib', 'SKU menu yang sudah ada.', 'ES-TEH-01'],
                    ['Nama Produk', 'opsional', 'Hanya sebagai keterangan.', 'Es Teh Manis'],
                    ['Nama Harga', 'wajib', 'Label pilihan di Kasir, mis. Jumbo.', 'Jumbo'],
                    ['Harga', 'wajib', 'Harga normal pilihan ini.', '9000'],
                    ['Harga Online', 'opsional', 'Kosong = sama dengan harga.', ''],
                    ['Kode Warung', 'opsional', 'Kosong = berlaku di semua cabang.', ''],
                ]],
            ],
            'rules' => [
                'Baris pertama berisi judul kolom persis seperti template.',
                'SKU yang sudah ada (dengan jenis yang sama) diperbarui; SKU baru dibuat.',
                'Sheet Harga Warung dan Pilihan Harga boleh dihapus bila tidak dipakai.',
                'Simpan SKU dan barcode sebagai teks agar nol di depan tidak hilang (template sudah diatur begitu).',
            ],
        ],
    ];

    public const STATUS_LABELS = ['wajib' => 'Wajib', 'menu' => 'Wajib untuk menu', 'opsional' => 'Opsional'];

    /** Sheet yang ditampilkan di modal (SUPPORT dan CV memakai kolom yang sama dengan MENTAH). */
    public static function guideSheets(string $format): array
    {
        return array_values(array_filter(self::FORMATS[$format]['sheets'], fn (array $sheet) => isset($sheet['title'])));
    }

    /** Template kosong + sheet Petunjuk. Contoh data hanya ada di Petunjuk agar tidak ikut terimpor. */
    public static function workbook(string $format, Collection $stores): Spreadsheet
    {
        $definition = self::FORMATS[$format];
        $book = new Spreadsheet;
        $guide = $book->getActiveSheet()->setTitle('Petunjuk');
        $row = 1;
        $guide->setCellValue('A'.$row, 'Template impor '.$definition['label'].' · POS Warung')->getStyle('A'.$row)->getFont()->setBold(true)->setSize(14);
        $row += 2;
        $guide->setCellValue('A'.$row++, $definition['summary']);
        $guide->setCellValue('A'.$row++, 'Cara impor: isi sheet data, simpan sebagai .xlsx, lalu buka Produk → Import Excel. Sheet Petunjuk ini diabaikan saat impor.');
        foreach ($definition['rules'] as $rule) {
            $guide->setCellValue('A'.$row++, '• '.$rule);
        }
        if ($format === 'standard' && $stores->isNotEmpty()) {
            $guide->setCellValue('A'.$row++, '• Kode warung yang tersedia: '.$stores->map(fn ($store) => $store->code.' = '.$store->name)->implode(', ').'.');
        }
        foreach (self::guideSheets($format) as $sheet) {
            $row++;
            $guide->setCellValue('A'.$row, $sheet['title'])->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
            $row++;
            $guide->fromArray(['Kolom', 'Status', 'Keterangan', 'Contoh isi'], null, 'A'.$row);
            self::headerStyle($guide, 'A'.$row.':D'.$row, '2D3A58');
            $row++;
            foreach ($sheet['columns'] as [$column, $status, $description, $example]) {
                $guide->setCellValueExplicit('A'.$row, $column, DataType::TYPE_STRING);
                $guide->setCellValue('B'.$row, self::STATUS_LABELS[$status]);
                $guide->setCellValue('C'.$row, $description);
                $guide->setCellValueExplicit('D'.$row, $example, DataType::TYPE_STRING);
                if ($status !== 'opsional') {
                    $guide->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
                }
                $row++;
            }
        }
        $guide->getColumnDimension('A')->setWidth(22);
        $guide->getColumnDimension('B')->setWidth(18);
        $guide->getColumnDimension('C')->setWidth(80);
        $guide->getColumnDimension('D')->setWidth(28);
        $guide->getStyle('C1:C'.$row)->getAlignment()->setWrapText(true);

        foreach ($definition['sheets'] as $sheet) {
            $data = $book->createSheet()->setTitle($sheet['name']);
            $data->fromArray(array_column($sheet['columns'], 0), null, 'A1');
            foreach ($sheet['columns'] as $index => [$column, $status]) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                self::headerStyle($data, $letter.'1', $status === 'opsional' ? '78978A' : '2D3A58');
                $data->getColumnDimension($letter)->setWidth(max(12, mb_strlen($column) + 6));
                // Kode ditulis sebagai teks: nol di depan dan barcode panjang tidak berubah.
                if (in_array($column, ['SKU', 'BARCODE', 'Barcode', 'Kode Warung'], true)) {
                    $data->getStyle($letter.'2:'.$letter.'1000')->getNumberFormat()->setFormatCode('@');
                }
            }
            $data->freezePane('A2');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private static function headerStyle(Worksheet $sheet, string $range, string $color): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
    }
}
