<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockCount;
use App\Support\SheetValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Impor workbook menu & stok outlet ("POS MENU ALL OUTLET & SKU", "Stok Bahan Baku",
 * "Stok Olahan Hari Ini", "Stok CV ...") tanpa perlu diubah ke template Produk.
 *
 * - Sheet MATANG / file olahan (ada kolom NOMINAL OFLINE) menjadi menu siap jual.
 * - Sheet MENTAH, SUPPORT, CV / file bahan baku menjadi bahan baku.
 * - Kolom SKU pada menu adalah SKU bahan bakunya dan boleh dipakai beberapa menu
 *   (mis. BA-04 untuk Ayam Bakar/Goreng Negeri Size 1/2). SKU itu disimpan di
 *   `ingredient_sku`; menu yang berbagi SKU mendapat SKU sendiri BA-04-1, BA-04-2, ...
 * - Kolom stok opsional (STOK / STOK AWAL / JUMLAH / QTY / SISA) menyetel stok cabang
 *   aktif seperti stock opname; tanpa kolom itu stok yang sudah ada tidak berubah.
 *
 * Impor ulang file yang sama memperbarui produk yang sama, tidak menggandakan.
 */
final class OutletCatalogImporter
{
    private const MENU_SHEETS = ['MATANG', 'OLAHAN', 'MENU', 'STOK OLAHAN'];

    private const INGREDIENT_SHEETS = ['MENTAH', 'BAHAN BAKU', 'SUPPORT', 'CV', 'GUDANG', 'STOK BAHAN BAKU'];

    private const COLUMNS = [
        'name' => ['nama_produk', 'item', 'bahan_baku', 'nama_barang', 'nama_menu', 'nama', 'produk'],
        'unit' => ['satuan', 'unit'],
        'category' => ['kategori', 'category'],
        'sku' => ['sku', 'kode', 'kode_sku'],
        'barcode' => ['barcode'],
        'minimum' => ['min_stok', 'min_stock', 'stok_minimum', 'minimum_stok', 'min'],
        'price' => ['nominal_ofline', 'nominal_offline', 'harga_offline', 'harga_normal', 'harga_jual', 'harga'],
        'online_price' => ['nominal_online', 'harga_online'],
        'purchase_price' => ['harga_beli', 'hpp'],
        'quantity' => ['stok', 'stok_awal', 'stok_hari_ini', 'stok_fisik', 'jumlah', 'qty', 'sisa'],
    ];

    /** Warna & ikon kategori baru agar tombol kategori di Kasir langsung mudah dibedakan. */
    private const CATEGORY_STYLES = [
        'makanan' => ['#d06b4d', 'bi-egg-fried'],
        'snack' => ['#c9953a', 'bi-cookie'],
        'minuman' => ['#3f7fa8', 'bi-cup-straw'],
        'kering' => ['#8a7a5c', 'bi-box-seam'],
        'basah' => ['#5b8f73', 'bi-box-seam'],
        'support' => ['#7a7f8a', 'bi-bag'],
        'gudang' => ['#6d6a9c', 'bi-box-seam'],
        'produksi' => ['#9c5a6d', 'bi-box-seam'],
    ];

    /** @var Collection<string, Category> */
    private Collection $categories;

    /** @var Collection<int, Product> */
    private Collection $products;

    private array $stats = ['menu_created' => 0, 'menu_updated' => 0, 'ingredient_created' => 0, 'ingredient_updated' => 0, 'stock' => 0];

    private array $notes = [];

    public function __construct(private int $tenantId, private int $storeId, private int $userId) {}

    /** Workbook dikenali dari nama sheet atau judul kolom khas file outlet. */
    public static function detects(Spreadsheet $book): bool
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            if (in_array(Str::upper(trim($sheet->getTitle())), array_merge(self::MENU_SHEETS, self::INGREDIENT_SHEETS), true)) {
                return true;
            }
        }
        $header = self::headerRow($book->getSheet(0));

        return $header !== null && ! in_array('jenis', $header['columns'], true);
    }

    /** @return array{message: string, notes: list<string>} */
    public function import(Spreadsheet $book): array
    {
        $sheets = ['ingredient' => [], 'menu' => []];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $header = self::headerRow($sheet);
            if ($header === null) {
                continue;
            }
            $sheets[$this->kindOf($sheet, $header['columns'])][] = [$sheet->getTitle(), $this->records($sheet, $header)];
        }

        DB::transaction(function () use ($sheets) {
            $this->categories = Category::withTrashed()->where('tenant_id', $this->tenantId)->get()->keyBy(fn (Category $category) => Str::lower($category->name));
            $this->products = Product::withTrashed()->where('tenant_id', $this->tenantId)->get();
            // Bahan baku lebih dulu supaya menu dapat langsung ditautkan ke SKU bahannya.
            foreach ($sheets['ingredient'] as [$title, $records]) {
                foreach ($records as $record) {
                    $this->importIngredient($title, $record);
                }
            }
            $menuRecords = collect($sheets['menu'])->flatMap(fn ($sheet) => collect($sheet[1])->map(fn ($record) => $record + ['sheet' => $sheet[0]]));
            $groupSizes = $menuRecords->countBy(fn ($record) => Str::upper($record['sku']));
            foreach ($menuRecords as $record) {
                $this->importMenu($record, $groupSizes[Str::upper($record['sku'])] ?? 1);
            }
        }, 3);

        $parts = [];
        if ($this->stats['menu_created'] + $this->stats['menu_updated']) {
            $parts[] = "{$this->stats['menu_created']} menu baru, {$this->stats['menu_updated']} menu diperbarui";
        }
        if ($this->stats['ingredient_created'] + $this->stats['ingredient_updated']) {
            $parts[] = "{$this->stats['ingredient_created']} bahan baku baru, {$this->stats['ingredient_updated']} bahan baku diperbarui";
        }
        if ($this->stats['stock']) {
            $parts[] = "stok {$this->stats['stock']} produk disetel";
        }

        return [
            'message' => $parts ? 'Impor workbook outlet selesai: '.implode('; ', $parts).'.' : 'Tidak ada baris produk yang dapat diimpor.',
            'notes' => array_slice($this->notes, 0, 30),
        ];
    }

    /** Baris judul = baris pertama (maks. 10 baris teratas) yang memiliki kolom nama dan SKU. */
    private static function headerRow(Worksheet $sheet): ?array
    {
        $rows = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().min(10, $sheet->getHighestRow()), null, true, false, false);
        foreach ($rows as $index => $row) {
            $columns = array_map(fn ($value) => Str::slug((string) $value, '_'), $row);
            $hasName = (bool) array_intersect(self::COLUMNS['name'], $columns);
            if ($hasName && in_array('sku', $columns, true) && ! in_array('nama', $columns, true)) {
                return ['row' => $index + 1, 'columns' => $columns];
            }
        }

        return null;
    }

    private function kindOf(Worksheet $sheet, array $columns): string
    {
        $title = Str::upper(trim($sheet->getTitle()));
        if (in_array($title, self::MENU_SHEETS, true)) {
            return 'menu';
        }
        if (in_array($title, self::INGREDIENT_SHEETS, true)) {
            return 'ingredient';
        }

        return array_intersect(self::COLUMNS['price'], $columns) ? 'menu' : 'ingredient';
    }

    /** @return list<array<string, mixed>> Baris berisi, dengan kunci kolom baku dan nomor baris Excel. */
    private function records(Worksheet $sheet, array $header): array
    {
        $index = [];
        foreach (self::COLUMNS as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (($position = array_search($alias, $header['columns'], true)) !== false) {
                    $index[$key] = $position;
                    break;
                }
            }
        }
        $records = [];
        $rows = $sheet->rangeToArray('A'.($header['row'] + 1).':'.$sheet->getHighestColumn().$sheet->getHighestRow(), null, true, false, false);
        foreach ($rows as $offset => $row) {
            $record = ['row' => $header['row'] + 1 + $offset];
            foreach ($index as $key => $position) {
                $record[$key] = $row[$position] ?? null;
            }
            $record['name'] = preg_replace('/\s+/u', ' ', SheetValue::text($record['name'] ?? ''));
            $record['sku'] = Str::upper(SheetValue::text($record['sku'] ?? ''));
            if ($record['name'] === '' && $record['sku'] === '') {
                continue;
            }
            if ($record['name'] === '' || $record['sku'] === '') {
                $this->notes[] = "{$sheet->getTitle()} baris {$record['row']}: nama atau SKU kosong, dilewati.";

                continue;
            }
            $records[] = $record;
        }

        return $records;
    }

    private function importIngredient(string $sheet, array $record): void
    {
        $product = $this->products->first(fn (Product $product) => $product->product_type === 'ingredient' && Str::upper($product->sku) === $record['sku']);
        $values = ['name' => $record['name'], 'product_type' => 'ingredient'] + $this->commonValues($record, $product);
        $product = $this->save($product, $values + ['sku' => $record['sku']], 'ingredient');
        $this->applyStock($product, $record, $sheet);
    }

    private function importMenu(array $record, int $groupSize): void
    {
        $price = SheetValue::number($record['price'] ?? null);
        if ($price === null || $price < 0) {
            $this->notes[] = "{$record['sheet']} baris {$record['row']}: harga {$record['name']} kosong/tidak valid, dilewati.";

            return;
        }
        $name = Str::lower($record['name']);
        $menus = $this->products->where('product_type', 'menu');
        // Menu dicocokkan lewat SKU asal + nama, sehingga urutan baris boleh berubah saat impor ulang.
        $product = $menus->first(fn (Product $menu) => Str::upper((string) $menu->ingredient_sku) === $record['sku'] && Str::lower($menu->name) === $name)
            ?? ($groupSize === 1 ? $menus->first(fn (Product $menu) => Str::upper($menu->sku) === $record['sku']) : null)
            ?? $menus->first(fn (Product $menu) => $menu->ingredient_sku === null && Str::lower($menu->name) === $name);
        $online = SheetValue::number($record['online_price'] ?? null);
        $values = [
            'name' => $record['name'], 'product_type' => 'menu', 'ingredient_sku' => $record['sku'],
            'selling_price' => round($price), 'online_selling_price' => round($online && $online > 0 ? $online : $price),
        ] + $this->commonValues($record, $product);
        if (! $product) {
            $values['sku'] = $this->menuSku($record['sku'], $groupSize);
        }
        $product = $this->save($product, $values, 'menu');
        $this->applyStock($product, $record, $record['sheet']);
    }

    /** SKU menu: SKU asal bila hanya dipakai satu menu, selain itu SKU-1, SKU-2, ... yang belum terpakai. */
    private function menuSku(string $base, int $groupSize): string
    {
        $taken = $this->products->where('product_type', 'menu')->map(fn (Product $product) => Str::upper($product->sku))->flip();
        if ($groupSize === 1 && ! $taken->has($base)) {
            return $base;
        }
        for ($i = 1; $taken->has("{$base}-{$i}"); $i++);

        return "{$base}-{$i}";
    }

    private function commonValues(array $record, ?Product $product): array
    {
        $values = ['unit' => Str::lower(SheetValue::text($record['unit'] ?? '')) ?: ($product?->unit ?? 'pcs'), 'is_active' => true, 'deleted_at' => null];
        if (($category = SheetValue::text($record['category'] ?? '')) !== '') {
            $values['category_id'] = $this->category($category)->id;
        }
        // Sel kosong tidak menghapus nilai yang sudah ada (mis. sheet MENTAH tanpa MIN STOK).
        if (($minimum = SheetValue::number($record['minimum'] ?? null)) !== null) {
            $values['minimum_stock'] = max(0, (int) round($minimum));
        }
        if (($purchase = SheetValue::number($record['purchase_price'] ?? null)) !== null) {
            $values['purchase_price'] = $purchase;
        }
        if (($barcode = SheetValue::text($record['barcode'] ?? '')) !== '') {
            $owner = $this->products->first(fn (Product $other) => $other->barcode === $barcode && $other->id !== $product?->id);
            $owner ? $this->notes[] = "Barcode {$barcode} sudah dipakai {$owner->name}; barcode {$record['name']} tidak diubah." : $values['barcode'] = $barcode;
        }

        return $values;
    }

    private function save(?Product $product, array $values, string $type): Product
    {
        if ($product) {
            $product->fill($values)->save();
            $this->stats[$type.'_updated']++;

            return $product;
        }
        $product = Product::create($values + ['tenant_id' => $this->tenantId, 'selling_price' => 0, 'minimum_stock' => 0]);
        $this->products->push($product);
        $this->stats[$type.'_created']++;

        return $product;
    }

    private function category(string $name): Category
    {
        $name = Str::title(Str::lower($name));
        $key = Str::lower($name);
        if ($category = $this->categories->get($key)) {
            if ($category->trashed()) {
                $category->restore();
            }

            return $category;
        }
        [$color, $icon] = self::CATEGORY_STYLES[$key] ?? ['#78978a', null];

        return $this->categories[$key] = Category::create(['tenant_id' => $this->tenantId, 'name' => $name, 'color' => $color, 'icon' => $icon]);
    }

    /**
     * Stok cabang aktif selalu tersedia. Bila kolom stok diisi, saldo disetel ke angka itu
     * dan selisihnya dicatat sebagai stock opname (terlihat di riwayat pergerakan stok).
     */
    private function applyStock(Product $product, array $record, string $sheet): void
    {
        $stock = $product->product_type === 'menu' ? $this->menuStock($product) : ProductStock::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'store_id' => $this->storeId, 'product_id' => $product->id], ['quantity' => 0]
        );
        $quantity = SheetValue::number($record['quantity'] ?? null);
        if ($quantity === null) {
            return;
        }
        if ($quantity < 0) {
            $this->notes[] = "{$sheet} baris {$record['row']}: stok {$product->name} negatif, dilewati.";

            return;
        }
        $stock = $stock->newQuery()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $expected = (float) $stock->quantity;
        StockCount::updateOrCreate(
            ['store_id' => $this->storeId, 'product_id' => $product->id, 'count_date' => today()],
            ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'expected_quantity' => $expected, 'actual_quantity' => $quantity, 'notes' => 'Impor Excel '.$sheet]
        );
        $delta = $quantity - $expected;
        if (abs($delta) > 0.0005) {
            $stock->update(['quantity' => $quantity]);
            DB::table('stock_movements')->insert([
                'tenant_id' => $this->tenantId, 'store_id' => $this->storeId, 'product_id' => $product->id,
                'user_id' => $this->userId, 'type' => $delta > 0 ? 'adjustment_in' : 'adjustment_out',
                'activity' => 'stock_opname', 'quantity' => $delta, 'reference' => 'IMPORT-'.today()->format('Ymd'),
                'notes' => 'Stok diimpor dari Excel ('.$sheet.')', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->stats['stock']++;
    }

    /** Stok olahan hari ini; sisa hari sebelumnya dibawa seperti WarungController::menuStockForDate(). */
    private function menuStock(Product $product): DailyMenuStock
    {
        $query = fn () => DailyMenuStock::where('tenant_id', $this->tenantId)->where('store_id', $this->storeId)->where('product_id', $product->id);
        if ($stock = $query()->whereDate('stock_date', today())->first()) {
            return $stock;
        }
        $carryOver = (float) ($query()->whereDate('stock_date', '<', today())->latest('stock_date')->value('quantity') ?? 0);

        return DailyMenuStock::create([
            'tenant_id' => $this->tenantId, 'store_id' => $this->storeId, 'product_id' => $product->id,
            'stock_date' => today()->toDateString(), 'quantity' => $carryOver,
        ]);
    }
}
