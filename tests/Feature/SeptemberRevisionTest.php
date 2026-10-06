<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConnectedDevice;
use App\Models\DailyMenuStock;
use App\Models\InventoryAsset;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductStock;
use App\Models\ProductStorePrice;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\EposReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Revisi 28 September 2026: catatan revisi tulisan tangan poin 2–12. */
class SeptemberRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Revisi', 'slug' => 'warung-revisi']);
        Role::provisionDefaults($tenant->id);
        $storeA = Store::create(['tenant_id' => $tenant->id, 'name' => 'Warung A', 'code' => 'WA', 'is_active' => true]);
        $storeB = Store::create(['tenant_id' => $tenant->id, 'name' => 'Warung B', 'code' => 'WB', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'store_id' => $storeA->id, 'name' => 'Admin', 'email' => 'admin@revisi.test', 'role' => 'superadmin', 'is_active' => true, 'password' => 'password']);
        $cashierB = User::create(['tenant_id' => $tenant->id, 'store_id' => $storeB->id, 'name' => 'Kasir B', 'email' => 'kasir.b@revisi.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Minuman', 'color' => '#4477aa', 'icon' => 'bi-cup-straw']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Es Teh', 'product_type' => 'menu', 'sku' => 'ET-1',
            'unit' => 'gelas', 'purchase_price' => 2000, 'selling_price' => 6000, 'online_selling_price' => 6000, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        foreach ([$storeA, $storeB] as $store) {
            DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 10]);
        }

        return compact('tenant', 'storeA', 'storeB', 'admin', 'cashierB', 'category', 'menu');
    }

    private function checkout(User $user, Store $store, array $items, array $extra = [])
    {
        return $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', $extra + [
            'items' => $items, 'service_type' => 'takeaway', 'payments' => [['method' => 'cash', 'amount' => 1000000]],
        ]);
    }

    // Poin 9 · Menu consolidated: harga dapat berbeda per warung.
    public function test_price_can_differ_per_store(): void
    {
        ['storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'cashierB' => $cashierB, 'menu' => $menu] = $this->setupWarung();
        ProductStorePrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'store_id' => $storeB->id, 'selling_price' => 7000]);

        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 1]])->assertOk();
        $this->checkout($cashierB, $storeB, [['id' => $menu->id, 'qty' => 1]])->assertOk();

        $this->assertEquals(6000, Transaction::where('store_id', $storeA->id)->value('total'));
        $this->assertEquals(7000, Transaction::where('store_id', $storeB->id)->value('total'));
        // Tanpa harga online khusus, pesanan online di Warung B ikut harga Warung B.
        $this->assertSame(7000.0, $menu->fresh()->priceAt($storeB->id, true));
        $this->actingAs($cashierB)->get('/kasir')->assertOk()->assertSee('Rp 7.000', false);
    }

    public function test_menu_can_be_hidden_for_one_store(): void
    {
        ['storeB' => $storeB, 'cashierB' => $cashierB, 'menu' => $menu] = $this->setupWarung();
        ProductStorePrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'store_id' => $storeB->id, 'is_available' => false]);

        $this->actingAs($cashierB)->get('/kasir')->assertOk()->assertViewHas('posProducts', fn ($products) => $products->isEmpty());
        $this->checkout($cashierB, $storeB, [['id' => $menu->id, 'qty' => 1]])->assertStatus(422)->assertJsonFragment(['message' => 'Menu Es Teh tidak dijual di cabang ini.']);
    }

    // Poin 5 · SKU & harga: satu SKU dengan banyak harga.
    public function test_one_sku_can_be_sold_with_multiple_prices_sharing_one_stock(): void
    {
        ['storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'cashierB' => $cashierB, 'menu' => $menu] = $this->setupWarung();
        $jumbo = ProductPrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'label' => 'Jumbo', 'price' => 9000]);
        $onlyA = ProductPrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'store_id' => $storeA->id, 'label' => 'Paket A', 'price' => 5000]);

        $response = $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2], ['id' => $menu->id, 'price_id' => $jumbo->id, 'qty' => 3]])->assertOk();
        $transaction = Transaction::where('invoice_no', $response->json('invoice'))->firstOrFail();
        $this->assertEquals(6000 * 2 + 9000 * 3, $transaction->total);
        $this->assertDatabaseHas('transaction_items', ['transaction_id' => $transaction->id, 'product_price_id' => $jumbo->id, 'price_label' => 'Jumbo', 'product_name' => 'Es Teh (Jumbo)', 'price' => 9000]);
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $storeA->id, 'product_id' => $menu->id, 'quantity' => 5]);

        // Stok dihitung gabungan semua pilihan harga.
        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 3], ['id' => $menu->id, 'price_id' => $jumbo->id, 'qty' => 3]])->assertStatus(422);
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $storeA->id, 'product_id' => $menu->id, 'quantity' => 5]);
        // Pilihan harga khusus Warung A tidak dapat dipakai di Warung B.
        $this->checkout($cashierB, $storeB, [['id' => $menu->id, 'price_id' => $onlyA->id, 'qty' => 1]])->assertStatus(422);
    }

    public function test_price_modal_saves_store_prices_and_price_options(): void
    {
        ['storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $old = ProductPrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'label' => 'Lama', 'price' => 1000]);

        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->put("/produk/{$menu->id}/harga", [
            'stores' => [
                $storeA->id => ['selling_price' => '', 'online_selling_price' => '', 'is_available' => '1'],
                $storeB->id => ['selling_price' => '7000', 'online_selling_price' => '8000', 'is_available' => '1'],
            ],
            'prices' => [
                ['label' => 'Porsi besar', 'price' => '9000', 'online_price' => '', 'store_id' => ''],
                ['label' => 'Promo B', 'price' => '5000', 'store_id' => (string) $storeB->id],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('product_store_prices', ['product_id' => $menu->id, 'store_id' => $storeA->id]);
        $this->assertDatabaseHas('product_store_prices', ['product_id' => $menu->id, 'store_id' => $storeB->id, 'selling_price' => 7000, 'online_selling_price' => 8000]);
        $this->assertDatabaseMissing('product_prices', ['id' => $old->id]);
        $this->assertDatabaseHas('product_prices', ['product_id' => $menu->id, 'label' => 'Porsi besar', 'store_id' => null, 'price' => 9000]);
        $this->assertDatabaseHas('product_prices', ['product_id' => $menu->id, 'label' => 'Promo B', 'store_id' => $storeB->id]);
        $this->actingAs($admin)->get('/produk')->assertOk()->assertSee('+2 pilihan harga', false);
    }

    public function test_single_store_role_cannot_set_price_for_another_store(): void
    {
        ['tenant' => $tenant, 'storeA' => $storeA, 'storeB' => $storeB, 'menu' => $menu] = $this->setupWarung();
        $opsAdmin = User::create(['tenant_id' => $tenant->id, 'store_id' => $storeA->id, 'name' => 'Ops', 'email' => 'ops@revisi.test', 'role' => 'ops_admin', 'is_active' => true, 'password' => 'password']);
        $foreign = ProductPrice::create(['tenant_id' => $tenant->id, 'product_id' => $menu->id, 'store_id' => $storeB->id, 'label' => 'Khusus B', 'price' => 4000]);

        $this->actingAs($opsAdmin)->put("/produk/{$menu->id}/harga", ['stores' => [$storeB->id => ['selling_price' => '1']]])->assertForbidden();
        // Pilihan harga cabang lain tidak terlihat sehingga tidak ikut terhapus.
        $this->actingAs($opsAdmin)->put("/produk/{$menu->id}/harga", ['prices' => [['label' => 'Kecil', 'price' => '5000', 'store_id' => (string) $storeA->id]]])->assertRedirect();
        $this->assertDatabaseHas('product_prices', ['id' => $foreign->id]);
    }

    // Poin 2 · Export: SKU/barcode tidak rusak dan pilihan harga ikut tersimpan.
    public function test_product_export_keeps_text_codes_and_round_trips_prices(): void
    {
        ['storeB' => $storeB, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $menu->update(['sku' => '000123', 'barcode' => '8991234567890', 'icon' => '🧋']);
        ProductStorePrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'store_id' => $storeB->id, 'selling_price' => 7000]);
        ProductPrice::create(['tenant_id' => $menu->tenant_id, 'product_id' => $menu->id, 'label' => 'Jumbo', 'price' => 9000]);

        $response = $this->actingAs($admin)->get('/produk/export');
        $response->assertOk()->assertHeader('content-length');
        $path = storage_path('app/testing/produk-export.xlsx');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Produk');
        $this->assertSame(['Produk', 'Harga Warung', 'Pilihan Harga'], $book->getSheetNames());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame('000123', $sheet->getCell('A2')->getValue());
        $this->assertSame('8991234567890', $sheet->getCell('B2')->getValue());
        $this->assertSame('🧋', $sheet->getCell('K2')->getValue());
        $this->assertSame('WB', $book->getSheetByName('Harga Warung')->getCell('C2')->getValue());
        $this->assertSame('Jumbo', $book->getSheetByName('Pilihan Harga')->getCell('C2')->getValue());
        $book->disconnectWorksheets();

        ProductStorePrice::query()->delete();
        ProductPrice::query()->delete();
        $menu->update(['selling_price' => 1, 'icon' => null]);
        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])->assertRedirect()->assertSessionHasNoErrors();

        $menu->refresh();
        $this->assertEquals(6000, $menu->selling_price);
        $this->assertSame('000123', $menu->sku);
        $this->assertSame('🧋', $menu->icon);
        $this->assertDatabaseHas('product_store_prices', ['product_id' => $menu->id, 'store_id' => $storeB->id, 'selling_price' => 7000]);
        $this->assertDatabaseHas('product_prices', ['product_id' => $menu->id, 'label' => 'Jumbo', 'price' => 9000]);
    }

    public function test_import_reads_formatted_rupiah_and_keeps_icon_when_column_missing(): void
    {
        ['admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $menu->update(['icon' => 'bi-cup-hot']);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['SKU', 'Nama', 'Harga Normal', 'Harga Beli'], null, 'A1');
        // Sel teks seperti ketikan pengguna: "15.000" berarti lima belas ribu rupiah.
        foreach (['A2' => 'ET-1', 'B2' => 'Es Teh', 'C2' => '15.000', 'D2' => '2,500'] as $cell => $value) {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
        $path = storage_path('app/testing/produk-lama.xlsx');
        File::ensureDirectoryExists(dirname($path));
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])->assertRedirect();

        $menu->refresh();
        $this->assertEquals(15000, $menu->selling_price);
        $this->assertEquals(2500, $menu->purchase_price);
        $this->assertSame('bi-cup-hot', $menu->icon);

        // CSV dari Excel berbahasa Indonesia memakai titik sebagai pemisah ribuan.
        $csv = storage_path('app/testing/produk.csv');
        File::put($csv, "SKU,Nama,Harga Normal\n000777,Kopi,\"12.500\"\n");
        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($csv, 'produk.csv', 'text/csv', null, true)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => '000777', 'name' => 'Kopi', 'selling_price' => 12500]);
    }

    public function test_report_export_formats_quantity_and_lists_split_payments(): void
    {
        ['tenant' => $tenant, 'storeA' => $storeA, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'M-1', 'qr_code' => 'QR-1', 'name' => 'Rina', 'deposit_balance' => 5000, 'is_active' => true]);
        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2]], [
            'member_id' => $member->id, 'payments' => [['method' => 'deposit', 'amount' => 5000], ['method' => 'cash', 'amount' => 7000]],
        ])->assertOk();

        $response = $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/laporan/export?period=today');
        $path = storage_path('app/testing/laporan-split.xlsx');
        File::put($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheetByName('Transaksi');
        $this->assertSame('Es Teh × 2', $sheet->getCell('G5')->getValue());
        $this->assertStringContainsString('DEPOSIT 5.000', $sheet->getCell('H5')->getValue());
        $this->assertStringContainsString('CASH 7.000', $sheet->getCell('H5')->getValue());
        $this->assertEquals(12000, $sheet->getCell('K5')->getValue());
    }

    // Poin 3 · Icon: ikon menu dan kategori dapat diubah.
    public function test_product_and_category_icons_can_be_edited(): void
    {
        ['admin' => $admin, 'category' => $category, 'menu' => $menu] = $this->setupWarung();
        $this->assertSame('bi-cup-straw', $menu->displayIcon());

        $payload = ['name' => 'Es Teh', 'sku' => 'ET-1', 'unit' => 'gelas', 'purchase_price' => 2000, 'selling_price' => 6000, 'minimum_stock' => 2, 'category_id' => $category->id];
        $this->actingAs($admin)->put("/produk/{$menu->id}", $payload + ['icon' => '🧋'])->assertSessionHasNoErrors();
        $this->assertSame('🧋', $menu->fresh()->displayIcon());
        $this->actingAs($admin)->put("/produk/{$menu->id}", $payload + ['icon' => 'bi-bukan-ikon'])->assertSessionHasErrors('icon');
        $this->actingAs($admin)->put("/produk/{$menu->id}", $payload + ['icon' => '<script>'])->assertSessionHasErrors('icon');

        $this->actingAs($admin)->put("/produk/kategori/{$category->id}", ['name' => 'Minuman', 'color' => '#4477aa', 'icon' => 'bi-cup-hot'])->assertSessionHasNoErrors();
        $this->assertSame('bi-cup-hot', $category->fresh()->icon);
        $this->actingAs($admin)->get('/kasir')->assertOk()->assertSee('menu-icon', false)->assertSee('🧋', false);
    }

    // Poin 7 · Stok rusak / tidak layak untuk bahan mentah dan olahan matang.
    public function test_waste_column_reduces_raw_and_cooked_stock(): void
    {
        ['tenant' => $tenant, 'storeA' => $storeA, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $raw = Product::create(['tenant_id' => $tenant->id, 'name' => 'Daun teh', 'product_type' => 'ingredient', 'sku' => 'RAW-1', 'unit' => 'kg', 'purchase_price' => 1000, 'selling_price' => 0, 'minimum_stock' => 1, 'is_active' => true]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $storeA->id, 'product_id' => $raw->id, 'quantity' => 5]);
        $session = ['store_id' => $storeA->id];

        $this->actingAs($admin)->withSession($session)->patchJson("/gudang/stock/{$raw->id}", ['waste_quantity' => 1.5])->assertOk()->assertJsonPath('data.quantity', 3.5);
        $this->actingAs($admin)->withSession($session)->patchJson("/gudang/stock/{$raw->id}", ['waste_quantity' => 1])->assertOk()->assertJsonPath('data.quantity', 4);
        $this->actingAs($admin)->withSession($session)->post('/gudang/rusak', ['stock_type' => 'cooked', 'product_id' => $menu->id, 'quantity' => 2, 'reason' => 'Tumpah'])->assertRedirect();
        $this->actingAs($admin)->withSession($session)->post('/gudang/rusak', ['stock_type' => 'cooked', 'product_id' => $menu->id, 'quantity' => 1, 'reason' => 'Basi'])->assertRedirect();

        $this->assertEquals(4, ProductStock::where('product_id', $raw->id)->value('quantity'));
        $this->assertEquals(7, DailyMenuStock::where('store_id', $storeA->id)->where('product_id', $menu->id)->value('quantity'));
        $this->assertDatabaseHas('inventory_daily_records', ['product_id' => $menu->id, 'waste_quantity' => 3]);
        $this->assertSame(2, DB::table('stock_movements')->where('product_id', $menu->id)->where('activity', 'waste')->count());
        // Jenis stok harus sesuai produk, dan rusak tidak boleh melebihi sisa.
        $this->actingAs($admin)->withSession($session)->post('/gudang/rusak', ['stock_type' => 'raw', 'product_id' => $menu->id, 'quantity' => 1, 'reason' => 'Salah'])->assertNotFound();
        $this->actingAs($admin)->withSession($session)->patchJson("/gudang/stock/{$raw->id}", ['waste_quantity' => 50])->assertStatus(422);
        $this->actingAs($admin)->withSession($session)->get('/gudang')->assertOk()->assertSee('Rusak / tidak layak');
    }

    // Poin 6 · Inventaris peralatan seperti panci dan kompor.
    public function test_equipment_inventory_is_tracked_per_store_with_history(): void
    {
        ['tenant' => $tenant, 'storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'cashierB' => $cashierB] = $this->setupWarung();
        $managerB = User::create(['tenant_id' => $tenant->id, 'store_id' => $storeB->id, 'name' => 'Manager B', 'email' => 'mgr.b@revisi.test', 'role' => 'outlet_manager', 'is_active' => true, 'password' => 'password']);

        $this->actingAs($managerB)->post('/inventaris', ['name' => 'Kompor dua tungku', 'category' => 'Peralatan masak', 'unit' => 'unit', 'quantity_good' => 4, 'quantity_damaged' => 1, 'purchase_price' => '350000', 'location' => 'Dapur'])->assertRedirect()->assertSessionHasNoErrors();
        $asset = InventoryAsset::firstOrFail();
        $this->assertSame($storeB->id, $asset->store_id);
        $this->actingAs($managerB)->put("/inventaris/{$asset->id}", ['name' => 'Kompor dua tungku', 'category' => 'Peralatan masak', 'unit' => 'unit', 'quantity_good' => 3, 'quantity_damaged' => 2, 'purchase_price' => '350000', 'location' => 'Dapur'])->assertRedirect();
        $this->assertDatabaseHas('inventory_asset_logs', ['inventory_asset_id' => $asset->id, 'action' => 'updated']);
        $this->assertStringContainsString('jumlah rusak 1 → 2', $asset->logs()->where('action', 'updated')->value('summary'));

        $page = $this->actingAs($managerB)->get('/inventaris')->assertOk()->assertSee('Kompor dua tungku')->assertViewHas('summary', fn ($summary) => $summary['damaged'] === 2 && (float) $summary['value'] === 1750000.0);
        $page->assertSee('Rp 1.750.000', false);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id, 'view_scope' => 'store'])->get('/inventaris')->assertOk()->assertDontSee('Kompor dua tungku');
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id, 'view_scope' => 'consolidated'])->get('/inventaris')->assertOk()->assertSee('Kompor dua tungku');

        // Kasir tidak memiliki menu Inventaris; akun cabang lain tidak bisa mengubah barang.
        $this->actingAs($cashierB)->get('/inventaris')->assertForbidden();
        $managerA = User::create(['tenant_id' => $tenant->id, 'store_id' => $storeA->id, 'name' => 'Manager A', 'email' => 'mgr.a@revisi.test', 'role' => 'outlet_manager', 'is_active' => true, 'password' => 'password']);
        $this->actingAs($managerA)->delete("/inventaris/{$asset->id}")->assertNotFound();

        $export = $this->actingAs($managerB)->get('/inventaris/export')->assertOk();
        $this->assertStringStartsWith('PK', $export->streamedContent());
        $this->actingAs($managerB)->delete("/inventaris/{$asset->id}")->assertRedirect();
        $this->assertSoftDeleted($asset);
        $this->actingAs($managerB)->post("/inventaris/{$asset->id}/restore")->assertRedirect();
        $this->assertNotSoftDeleted($asset);
    }

    // Poin 10 · Tax & services.
    public function test_service_charge_and_tax_are_added_per_store_settings(): void
    {
        ['storeA' => $storeA, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $menu->update(['selling_price' => 50000]);
        $this->actingAs($admin)->post('/pengaturan/pajak-service', [
            'store_id' => $storeA->id, 'service_charge_percent' => 5, 'service_charge_types' => ['dine_in'],
            'tax_percent' => 10, 'tax_label' => 'PB1', 'tax_types' => ['dine_in', 'takeaway', 'online'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $dineIn = $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2]], ['service_type' => 'dine_in', 'table_number' => '3'])->assertOk();
        $trx = Transaction::where('invoice_no', $dineIn->json('invoice'))->firstOrFail();
        // 100.000 + service 5% (5.000) + PB1 10% dari 105.000 (10.500).
        $this->assertEquals(5000, $trx->service_charge);
        $this->assertEquals(10500, $trx->tax_amount);
        $this->assertEquals(115500, $trx->total);
        $this->assertSame('PB1', $trx->tax_label);

        $takeaway = $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 1]])->assertOk();
        $trx2 = Transaction::where('invoice_no', $takeaway->json('invoice'))->firstOrFail();
        $this->assertEquals(0, $trx2->service_charge);
        $this->assertEquals(55000, $trx2->total);

        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 1]], ['payments' => [['method' => 'cash', 'amount' => 50000]]])->assertStatus(422);
        $this->actingAs($admin)->get(route('transactions.print', $trx))->assertOk()->assertSee('Service (5%)', false)->assertSee('PB1 (10%)', false)->assertSee('Rp 115.500', false);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/laporan?period=today')->assertOk()
            ->assertViewHas('tax', 15500.0)->assertViewHas('service', 5000.0)
            // Laba bersih = omzet 170.500 − pajak 15.500 − HPP 6.000.
            ->assertViewHas('profit', 149000.0);
        $this->actingAs($admin)->get('/kasir')->assertOk()->assertSee('"tax_label":"PB1"', false);
    }

    public function test_only_permitted_roles_change_tax_settings(): void
    {
        ['storeB' => $storeB, 'cashierB' => $cashierB] = $this->setupWarung();
        $this->actingAs($cashierB)->post('/pengaturan/pajak-service', ['store_id' => $storeB->id, 'service_charge_percent' => 5, 'tax_percent' => 10, 'tax_label' => 'PB1'])->assertForbidden();
    }

    // Poin 11 · Printer kasir Epson ePOS.
    public function test_epson_epos_printer_receives_receipt_jobs_after_checkout(): void
    {
        ['storeA' => $storeA, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->post('/pengaturan/perangkat', [
            'name' => 'TM-m30 Kasir', 'type' => 'receipt_printer', 'driver' => 'epson_epos', 'store_id' => $storeA->id,
            'epos_host' => '192.168.1.50', 'epos_device_id' => 'local_printer', 'epos_paper' => '58', 'epos_timeout' => 10000,
            'epos_auto_print' => '1', 'epos_kitchen_copy' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $printer = ConnectedDevice::firstOrFail();
        $this->assertTrue($printer->isEpsonPrinter());
        $this->assertSame('http://192.168.1.50/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', $printer->eposUrl());

        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/kasir')->assertOk()->assertSee('TM-m30 Kasir');
        $response = $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2]])->assertOk();
        $response->assertJsonPath('epos.printer.url', $printer->eposUrl())->assertJsonPath('epos.printer.columns', 32)->assertJsonCount(2, 'epos.jobs');
        $customer = collect($response->json('epos.jobs.0'));
        $this->assertTrue($customer->contains(fn ($line) => ($line['text'] ?? '') === EposReceipt::pair('TOTAL', 'Rp 12.000', 32)));
        $this->assertSame('cut', $customer->last()['type']);
        $this->assertTrue(collect($response->json('epos.jobs.1'))->contains(fn ($line) => ($line['text'] ?? '') === '2 x Es Teh'));

        $trx = Transaction::latest('id')->firstOrFail();
        $this->actingAs($admin)->getJson(route('transactions.epos', $trx))->assertOk()->assertJsonCount(2, 'jobs');
        $this->actingAs($admin)->get(route('transactions.print', $trx))->assertOk()->assertSee('Epson · TM-m30 Kasir');
        $this->actingAs($admin)->put("/pengaturan/perangkat/{$printer->id}", [
            'name' => 'TM-m30 Kasir', 'type' => 'receipt_printer', 'driver' => 'epson_epos', 'store_id' => $storeA->id, 'epos_host' => 'bukan host!',
        ])->assertSessionHasErrors('epos_host');
    }

    public function test_rawbt_printer_settings_checkout_and_receipt_access(): void
    {
        ['storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'cashierB' => $cashierB, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->post('/pengaturan/perangkat', [
            'name' => 'Xantri BT-58D Pro', 'type' => 'receipt_printer', 'driver' => 'rawbt', 'store_id' => $storeA->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $printer = ConnectedDevice::firstOrFail();
        $this->assertTrue($printer->isRawbtPrinter());
        $this->assertSame(32, $printer->settings['columns']);
        $this->assertSame(58, $printer->settings['paper']);
        $this->actingAs($admin)->get('/pengaturan')->assertOk()->assertSee('Tes cetak RawBT');
        $this->actingAs($admin)->get('/kasir')->assertOk()->assertViewHas('rawbtPrinter', fn ($device) => $device->id === $printer->id);
        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2]])->assertOk();
        $transaction = Transaction::latest('id')->firstOrFail();
        $this->actingAs($admin)->get(route('transactions.print', $transaction))->assertOk()
            ->assertSee('Cetak struk')->assertSee('Salinan struk')->assertSee('intent:base64,');
        if (function_exists('imagecreatetruecolor')) {
            \Illuminate\Support\Facades\Storage::fake('public');
            $image = imagecreatetruecolor(8, 1);
            ob_start();
            imagepng($image);
            \Illuminate\Support\Facades\Storage::disk('public')->put('branding/rawbt-test.png', ob_get_clean());
            imagedestroy($image);
            $storeA->update(['receipt_show_logo' => true, 'logo_path' => 'branding/rawbt-test.png']);
            $receipt = $this->actingAs($admin)->get(route('transactions.print', $transaction))->assertOk();
            preg_match('/const rawbtUris = (.*);/', $receipt->getContent(), $matches);
            $uris = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            foreach ($uris as $copy => $uri) {
                $bytes = base64_decode(explode('#', substr($uri, strlen('intent:base64,')))[0], true);
                if ($copy === 'kitchen') {
                    $this->assertStringNotContainsString("\x1dv0\x00", $bytes);
                } else {
                    $this->assertStringContainsString("\x1dv0\x00", $bytes);
                }
            }
            $storeA->update(['logo_path' => 'branding/missing.png']);
            $this->actingAs($admin)->get(route('transactions.print', $transaction))->assertOk()
                ->assertSee('Unggah ulang logo')->assertSee('intent:base64,');
        }
        $this->actingAs($cashierB)->withSession(['store_id' => $storeB->id])->get('/kasir')->assertOk()->assertViewHas('rawbtPrinter', null);
        $this->actingAs($cashierB)->get(route('transactions.print', $transaction))->assertNotFound();
        $printer->update(['status' => 'inactive']);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get(route('transactions.print', $transaction))->assertOk()->assertDontSee('intent:base64,');
    }

    public function test_rawbt_bytes_preserve_receipt_layout_and_strip_control_characters(): void
    {
        $uri = \App\Support\RawbtReceipt::uri([
            [['type' => 'text', 'text' => "CUSTOMER\x1b@", 'bold' => true, 'align' => 'center', 'double' => true], ['type' => 'cut']],
            [['type' => 'text', 'text' => 'DAPUR'], ['type' => 'feed', 'lines' => 3]],
        ]);
        $this->assertStringEndsWith('#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;', $uri);
        $bytes = base64_decode(explode('#', substr($uri, strlen('intent:base64,')))[0], true);
        $this->assertStringStartsWith("\x1b@\x1bM\x00", $bytes);
        $this->assertStringContainsString("\x1ba\x01\x1bE\x01\x1d!\x01CUSTOMER@\n", $bytes);
        $this->assertStringContainsString("\x1ba\x00\x1bE\x00\x1d!\x00DAPUR\n\n\n\n", $bytes);
        $this->assertStringNotContainsString("\x1dV", $bytes);
    }

    public function test_epos_text_layout_fits_the_paper_width(): void
    {
        $this->assertSame('Subtotal'.str_repeat(' ', 32 - 8 - 9).'Rp 25.000', EposReceipt::pair('Subtotal', 'Rp 25.000', 32));
        $this->assertSame(32, strlen(EposReceipt::pair(str_repeat('Nama sangat panjang ', 3), 'Rp 1.000.000', 32)));
        foreach (EposReceipt::wrap('Nasi goreng spesial telur ceplok dengan kerupuk udang', 20) as $line) {
            $this->assertLessThanOrEqual(20, strlen($line));
        }
    }

    // Poin 12 · Membership: tombol History Membership.
    public function test_member_history_shows_deposits_and_transactions_by_store_access(): void
    {
        ['tenant' => $tenant, 'storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'cashierB' => $cashierB, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-9', 'qr_code' => 'QR-9', 'name' => 'Sari', 'deposit_balance' => 0, 'is_active' => true]);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->post("/member/{$member->id}/topup", ['amount' => 50000, 'payment_method' => 'cash'])->assertRedirect();
        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 1]], ['member_id' => $member->id, 'payments' => [['method' => 'deposit', 'amount' => 6000]]])->assertOk();
        $this->checkout($cashierB, $storeB, [['id' => $menu->id, 'qty' => 2]], ['member_id' => $member->id])->assertOk();

        $this->actingAs($admin)->get('/member')->assertOk()->assertSee(route('members.history', $member), false);
        $this->actingAs($admin)->get(route('members.history', $member))->assertOk()
            ->assertSee('Top up deposit')->assertSee('Warung B')
            ->assertViewHas('summary', fn ($summary) => $summary['visits'] === 2 && $summary['spent'] === 18000.0 && $summary['topups'] === 50000.0 && $summary['deposit_used'] === 6000.0);
        // Kasir Warung B hanya melihat aktivitas di cabangnya, saldo tetap global.
        $this->actingAs($cashierB)->get(route('members.history', $member))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['visits'] === 1 && $summary['topups'] === 0.0)
            ->assertSee('Rp 44.000', false);
        $export = $this->actingAs($admin)->get(route('members.history.export', $member))->assertOk();
        $this->assertStringStartsWith('PK', $export->streamedContent());

        $foreignTenant = Tenant::create(['name' => 'Lain', 'slug' => 'lain']);
        $foreign = Member::create(['tenant_id' => $foreignTenant->id, 'member_code' => 'X', 'qr_code' => 'X', 'name' => 'X']);
        $this->actingAs($admin)->get(route('members.history', $foreign))->assertNotFound();
    }

    // Poin 4 & 8 · Bug yang ditemukan saat pemeriksaan.
    public function test_inactive_session_store_falls_back_to_the_account_store(): void
    {
        ['storeA' => $storeA, 'storeB' => $storeB, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $storeB->update(['is_active' => false]);

        $this->actingAs($admin)->withSession(['store_id' => $storeB->id])->get('/kasir')->assertOk()->assertViewHas('activeStore', fn ($store) => $store->id === $storeA->id);
        $this->checkout($admin, $storeB, [['id' => $menu->id, 'qty' => 1]])->assertOk();
        $this->assertSame($storeA->id, Transaction::latest('id')->value('store_id'));
        $this->actingAs($admin)->post('/switch-store', ['store_id' => $storeB->id])->assertNotFound();
    }

    public function test_quantities_are_not_shown_as_thousands(): void
    {
        ['storeA' => $storeA, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $this->checkout($admin, $storeA, [['id' => $menu->id, 'qty' => 2]])->assertOk();

        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/laporan?period=today')->assertOk()->assertSee('×2</b>', false)->assertDontSee('×2.000', false);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/produk')->assertOk()->assertSee('8 / min. 2', false);
        $this->actingAs($admin)->withSession(['store_id' => $storeA->id])->get('/kasir/tutup-harian')->assertOk()->assertSee('2 gelas')->assertDontSee('2.000 gelas');
    }

    public function test_consolidated_transactions_list_every_store_but_void_stays_local(): void
    {
        ['storeA' => $storeA, 'admin' => $admin, 'cashierB' => $cashierB, 'storeB' => $storeB, 'menu' => $menu] = $this->setupWarung();
        $this->checkout($cashierB, $storeB, [['id' => $menu->id, 'qty' => 1]])->assertOk();
        $invoice = Transaction::latest('id')->value('invoice_no');

        $this->actingAs($admin)->withSession(['store_id' => $storeA->id, 'view_scope' => 'consolidated'])->get('/transaksi')->assertOk()
            ->assertSee($invoice)->assertSee('Warung B')->assertDontSee('data-url="'.route('transactions.destroy', Transaction::latest('id')->first()).'"', false);
    }
}
