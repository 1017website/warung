<?php

namespace Tests\Feature;

use App\Models\DailyMenuStock;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Revisi 14 Oktober 2026: cari stok, stok awal ke beberapa warung, dan pembulatan kasir. */
class October14RevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Rev', 'slug' => 'warung-rev']);
        Role::provisionDefaults($tenant->id);
        $wts = Store::create(['tenant_id' => $tenant->id, 'name' => 'WTS', 'code' => 'WTS', 'is_active' => true]);
        $psl = Store::create(['tenant_id' => $tenant->id, 'name' => 'PSL', 'code' => 'PSL', 'is_active' => true]);
        $wka = Store::create(['tenant_id' => $tenant->id, 'name' => 'WKA', 'code' => 'WKA', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'name' => 'Admin', 'email' => 'admin@rev.test', 'role' => 'superadmin', 'is_active' => true, 'password' => 'password']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Nasi Ayam', 'product_type' => 'menu', 'sku' => 'NA-1',
            'unit' => 'porsi', 'purchase_price' => 8000, 'selling_price' => 20000, 'online_selling_price' => 20000, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 20]);

        return compact('tenant', 'wts', 'psl', 'wka', 'admin', 'menu');
    }

    private function newProduct(array $extra = []): array
    {
        return $extra + [
            'name' => 'Bakwan Jagung', 'product_type' => 'menu', 'sku' => 'BKW-1', 'unit' => 'pcs',
            'purchase_price' => 1000, 'selling_price' => 3000, 'minimum_stock' => 5, 'initial_stock' => 15,
        ];
    }

    private function menuStock(Product $product, Store $store): ?float
    {
        $quantity = DailyMenuStock::where('store_id', $store->id)->where('product_id', $product->id)->whereDate('stock_date', today())->value('quantity');

        return $quantity === null ? null : (float) $quantity;
    }

    public function test_inventory_page_has_search_and_status_filter(): void
    {
        ['admin' => $admin, 'wts' => $wts] = $this->setupWarung();

        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang')->assertOk()
            ->assertSee('id="inventory-search"', false)
            ->assertSee('id="inventory-status-filter"', false)
            ->assertSee('data-search="nasi ayam na-1', false)
            ->assertSee('Tidak ada olahan yang cocok dengan pencarian.');
    }

    public function test_new_product_initial_stock_goes_to_every_selected_store(): void
    {
        ['admin' => $admin, 'wts' => $wts, 'psl' => $psl, 'wka' => $wka] = $this->setupWarung();

        // Mode consolidated: semua warung tercentang di form dan stok masuk ke warung yang dipilih.
        $this->actingAs($admin)->withSession(['view_scope' => 'consolidated'])->get('/produk')->assertOk()
            ->assertSee('Stok awal masuk ke warung')->assertSee('name="stock_store_ids[]" value="'.$psl->id.'" checked', false);
        $this->actingAs($admin)->withSession(['view_scope' => 'consolidated'])
            ->post('/produk', $this->newProduct(['stock_store_ids' => [$wts->id, $psl->id, $wka->id]]))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Produk berhasil ditambahkan dengan stok awal di PSL, WKA dan WTS.');
        $bakwan = Product::where('sku', 'BKW-1')->firstOrFail();
        foreach ([$wts, $psl, $wka] as $store) {
            $this->assertSame(15.0, $this->menuStock($bakwan, $store), $store->name);
        }
        // Halaman produk consolidated menampilkan stok setiap warung.
        $this->actingAs($admin)->withSession(['view_scope' => 'consolidated'])->get('/produk')->assertOk()
            ->assertSee('title="PSL">PSL 15</span>', false)->assertSee('title="WKA">WKA 15</span>', false);

        // Hanya sebagian warung yang dicentang: warung lain mulai dari 0.
        $this->actingAs($admin)->post('/produk', $this->newProduct(['sku' => 'TMP-1', 'name' => 'Tempe Mendoan', 'stock_store_ids' => [$psl->id]]))->assertSessionHasNoErrors();
        $tempe = Product::where('sku', 'TMP-1')->firstOrFail();
        $this->assertSame(15.0, $this->menuStock($tempe, $psl));
        $this->assertNull($this->menuStock($tempe, $wts));

        // Tanpa pilihan (akun satu cabang / form lama) stok tetap masuk ke cabang aktif.
        $this->actingAs($admin)->post('/produk', $this->newProduct(['sku' => 'GL-1', 'name' => 'Gula', 'product_type' => 'ingredient', 'stock_store_ids' => null]))->assertSessionHasNoErrors();
        $gula = Product::where('sku', 'GL-1')->firstOrFail();
        $this->assertSame([$wts->id], ProductStock::where('product_id', $gula->id)->pluck('store_id')->all());
    }

    public function test_stock_target_must_be_an_accessible_store(): void
    {
        ['admin' => $admin] = $this->setupWarung();
        $foreignTenant = Tenant::create(['name' => 'Lain', 'slug' => 'lain']);
        $foreign = Store::create(['tenant_id' => $foreignTenant->id, 'name' => 'Asing', 'code' => 'ASG', 'is_active' => true]);

        $this->actingAs($admin)->post('/produk', $this->newProduct(['stock_store_ids' => [$foreign->id]]))->assertSessionHasErrors('stock_store_ids.0');
        $this->assertFalse(Product::where('sku', 'BKW-1')->exists());
    }

    public function test_product_import_puts_stock_into_selected_stores(): void
    {
        ['admin' => $admin, 'wts' => $wts, 'psl' => $psl, 'wka' => $wka] = $this->setupWarung();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Produk')->fromArray([
            ['SKU', 'Barcode', 'Nama', 'Jenis', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Normal', 'Harga Online', 'Stok Minimum', 'Stok Awal'],
            ['BKW-1', null, 'Bakwan Jagung', 'menu', 'Gorengan', 'pcs', 1000, 3000, 3500, 5, 12],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'stores').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true), 'stock_store_ids' => [$psl->id, $wka->id]])
            ->assertSessionHas('success', '1 produk berhasil diimpor/diperbarui; stok masuk ke PSL dan WKA.');
        $bakwan = Product::where('sku', 'BKW-1')->firstOrFail();
        $this->assertSame(12.0, $this->menuStock($bakwan, $psl));
        $this->assertSame(12.0, $this->menuStock($bakwan, $wka));
        $this->assertNull($this->menuStock($bakwan, $wts));
    }

    private function stockFile(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Stok')->fromArray(array_merge([['SKU', 'Nama', 'Jenis', 'Satuan', 'Stok Sistem', 'Jumlah']], $rows));
        $path = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'stok.xlsx', null, null, true);
    }

    public function test_stock_template_lists_products_with_current_balance(): void
    {
        ['admin' => $admin, 'wts' => $wts] = $this->setupWarung();
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang')->assertOk()
            ->assertSee('Import stok dari Excel')->assertSee(route('inventory.import-template'), false);

        $response = $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang/import/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Stok')->toArray();
        $this->assertSame(['SKU', 'Nama', 'Jenis', 'Satuan', 'Stok Sistem', 'Jumlah'], $rows[0]);
        $this->assertSame(['NA-1', 'Nasi Ayam', 'Olahan', 'porsi', '20', null], $rows[1]);
    }

    public function test_stock_export_lists_ingredient_and_menu_tables(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'wts' => $wts] = $this->setupWarung();
        $ingredient = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Jahe', 'product_type' => 'ingredient', 'sku' => '007',
            'unit' => 'kg', 'purchase_price' => 1000, 'selling_price' => 0, 'minimum_stock' => 1, 'is_active' => true,
        ]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'product_id' => $ingredient->id, 'quantity' => 0]);
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang')->assertOk()
            ->assertSee('Ekspor Excel')->assertSee(route('inventory.export'), false);

        $response = $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang/export')->assertOk();
        $this->assertStringContainsString('stok-gudang-wts-', $response->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $raw = $book->getSheetByName('Bahan Baku')->toArray();
        $this->assertSame(['SKU', 'Bahan baku', 'Kategori', 'Satuan', 'Stok awal', 'Stok datang', 'Stok terpakai', 'Stok terolah', 'Rusak / tidak layak', 'Sisa stok', 'Batas minimum', 'Status', 'Keterangan'], $raw[0]);
        $this->assertSame(['007', 'Jahe', 'Bahan baku', 'kg', '0', '0', '0', '0', '0', '0', '1', 'KOSONG', null], $raw[1]);
        $menu = $book->getSheetByName('Olahan')->toArray();
        $this->assertSame('Sisa sistem', $menu[0][10]);
        $this->assertSame(['NA-1', 'Nasi Ayam', 'Menu', 'porsi'], array_slice($menu[1], 0, 4));
        $this->assertSame(['20', null, null, 'Belum opname'], array_slice($menu[1], 10, 4));
    }

    public function test_stock_import_sets_or_adds_stock_in_selected_stores(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'wts' => $wts, 'psl' => $psl, 'wka' => $wka, 'menu' => $menu] = $this->setupWarung();
        // SKU yang sama dipakai bahan baku dan menu (format workbook outlet).
        $ayam = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Ayam Karkas', 'product_type' => 'ingredient', 'sku' => 'NA-1',
            'unit' => 'ekor', 'purchase_price' => 30000, 'selling_price' => 0, 'minimum_stock' => 1, 'is_active' => true,
        ]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'product_id' => $ayam->id, 'quantity' => 4]);

        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->post('/gudang/import', [
            'mode' => 'set', 'stock_store_ids' => [$wts->id, $psl->id],
            'file' => $this->stockFile([
                ['NA-1', 'Nasi Ayam', 'Olahan', 'porsi', 20, 12],
                ['NA-1', 'Ayam Karkas', 'Bahan baku', 'ekor', 4, 9],
                ['XX-9', 'Tidak Ada', '', '', 0, 5],
                ['NA-1', 'Nasi Ayam', 'Olahan', 'porsi', 20, null],
            ]),
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Stok 2 produk disetel di PSL dan WTS.')
            ->assertSessionHas('import_notes', ['Baris 4: SKU XX-9 tidak ditemukan, dilewati.']);

        $this->assertSame(12.0, $this->menuStock($menu, $wts));
        $this->assertSame(12.0, $this->menuStock($menu, $psl));
        $this->assertNull($this->menuStock($menu, $wka));
        $this->assertEquals(9, ProductStock::where('store_id', $wts->id)->where('product_id', $ayam->id)->value('quantity'));
        $this->assertEquals(9, ProductStock::where('store_id', $psl->id)->where('product_id', $ayam->id)->value('quantity'));
        $this->assertDatabaseHas('stock_counts', ['store_id' => $wts->id, 'product_id' => $menu->id, 'expected_quantity' => 20, 'actual_quantity' => 12]);
        $this->assertDatabaseHas('stock_movements', ['store_id' => $wts->id, 'product_id' => $menu->id, 'activity' => 'stock_opname', 'quantity' => -8]);

        // Mode tambah: jumlah ditambahkan dan tercatat sebagai stok datang. Tanpa kolom Jenis, SKU ganda dibedakan lewat nama.
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->post('/gudang/import', [
            'mode' => 'add', 'file' => $this->stockFile([['NA-1', 'Ayam Karkas', '', '', '', 3], ['NA-1', 'Lain', '', '', '', 1]]),
        ])->assertSessionHas('success', 'Stok 1 produk ditambah di WTS.')
            ->assertSessionHas('import_notes', ['Baris 3: SKU NA-1 dipakai beberapa produk; isi kolom Jenis, dilewati.']);
        $this->assertEquals(12, ProductStock::where('store_id', $wts->id)->where('product_id', $ayam->id)->value('quantity'));
        $this->assertEquals(9, ProductStock::where('store_id', $psl->id)->where('product_id', $ayam->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['store_id' => $wts->id, 'product_id' => $ayam->id, 'activity' => 'adjustment', 'quantity' => 3]);

        $this->actingAs($admin)->post('/gudang/import', ['mode' => 'replace', 'file' => $this->stockFile([])])->assertSessionHasErrors('mode');
    }

    public function test_round_total_modes(): void
    {
        $store = new Store(['rounding_mode' => 'up', 'rounding_unit' => 500]);
        $this->assertSame(22500.0, $store->roundTotal(22200));
        $this->assertSame(22000.0, $store->roundTotal(22000));
        $store->rounding_mode = 'down';
        $this->assertSame(22000.0, $store->roundTotal(22499));
        // Pembulatan ke bawah tidak membuat tagihan menjadi nol.
        $this->assertSame(300.0, $store->roundTotal(300));
        $store->rounding_mode = 'nearest';
        $this->assertSame(22000.0, $store->roundTotal(22249));
        $store->rounding_unit = 1000;
        $this->assertSame(23000.0, $store->roundTotal(22500));
        $store->rounding_unit = 100;
        $this->assertSame(1000.0, $store->roundTotal(999.99));
        $store->rounding_mode = 'none';
        $this->assertSame(22222.0, $store->roundTotal(22222));
        // Nilai lama / tidak dikenal kembali ke default aman.
        $this->assertSame('none', (new Store(['rounding_mode' => 'aneh']))->roundingMode());
        $this->assertSame(100, (new Store(['rounding_unit' => 250]))->roundingUnit());
    }

    public function test_cashier_total_is_rounded_per_store_setting(): void
    {
        ['admin' => $admin, 'wts' => $wts, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($admin)->post('/pengaturan/pajak-service', [
            'store_id' => $wts->id, 'service_charge_percent' => 0, 'tax_percent' => 11, 'tax_label' => 'PB1', 'tax_types' => ['takeaway'],
            'rounding_mode' => 'up', 'rounding_unit' => 500,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('up', $wts->fresh()->rounding_mode);
        $this->actingAs($admin)->get('/kasir')->assertOk()->assertSee('"rounding_mode":"up","rounding_unit":500', false)->assertSee('id="rounding-row"', false);

        $order = ['items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway'];
        // 20.000 + PB1 11% (2.200) = 22.200 dibulatkan ke atas menjadi 22.500.
        $this->actingAs($admin)->postJson('/kasir/checkout', $order + ['payments' => [['method' => 'cash', 'amount' => 22200]]])->assertStatus(422);
        $response = $this->actingAs($admin)->postJson('/kasir/checkout', $order + ['payments' => [['method' => 'cash', 'amount' => 22500]]])->assertOk();
        $trx = Transaction::where('invoice_no', $response->json('invoice'))->firstOrFail();
        $this->assertEquals(2200, $trx->tax_amount);
        $this->assertEquals(300, $trx->rounding_amount);
        $this->assertEquals(22500, $trx->total);
        $this->assertEquals(0, $trx->change_amount);
        $this->actingAs($admin)->get(route('transactions.print', $trx))->assertOk()->assertSee('Pembulatan')->assertSee('Rp 22.500', false);

        // Ke bawah: selisih negatif tercatat dan open bill memakai total yang sama.
        $wts->update(['rounding_mode' => 'down', 'rounding_unit' => 1000]);
        $this->actingAs($admin)->postJson('/kasir/pending', $order)->assertOk();
        $bill = Transaction::where('status', 'pending')->firstOrFail();
        $this->assertEquals(-200, $bill->rounding_amount);
        $this->assertEquals(22000, $bill->total);

        $this->actingAs($admin)->post('/pengaturan/pajak-service', [
            'store_id' => $wts->id, 'service_charge_percent' => 0, 'tax_percent' => 0, 'tax_label' => 'PB1', 'rounding_mode' => 'sideways',
        ])->assertSessionHasErrors('rounding_mode');
    }
}
