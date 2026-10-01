<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Revisi 1 Oktober 2026: reservasi meja + DP dan impor workbook menu & stok outlet. */
class OctoberRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Oktober', 'slug' => 'warung-oktober']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Warung A', 'code' => 'WA', 'is_active' => true]);
        $otherStore = Store::create(['tenant_id' => $tenant->id, 'name' => 'Warung B', 'code' => 'WB', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Admin', 'email' => 'admin@oktober.test', 'role' => 'superadmin', 'is_active' => true, 'password' => 'password']);
        $cashier = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'kasir@oktober.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);
        $spv = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'SPV', 'email' => 'spv@oktober.test', 'role' => 'spv', 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('1234')]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan', 'color' => '#d06b4d']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Ayam Bakar', 'product_type' => 'menu', 'sku' => 'AB-1',
            'unit' => 'pcs', 'purchase_price' => 5000, 'selling_price' => 20000, 'online_selling_price' => 25000, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 20]);

        return compact('tenant', 'store', 'otherStore', 'admin', 'cashier', 'spv', 'menu');
    }

    private function reservation(User $user, array $extra = []): Reservation
    {
        $this->actingAs($user)->post('/reservasi', $extra + [
            'customer_name' => 'Bu Sari', 'phone' => '0812', 'reserved_date' => today()->toDateString(), 'reserved_time' => '19:00',
            'guests' => 6, 'table_number' => 'A-07', 'dp_amount' => 50000, 'dp_method' => 'cash',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Reservation::latest('id')->firstOrFail();
    }

    private function checkout(User $user, array $extra)
    {
        return $this->actingAs($user)->postJson('/kasir/checkout', $extra + ['service_type' => 'dine_in', 'table_number' => 'A-07']);
    }

    public function test_cashier_can_record_reservation_with_dp_and_it_appears_in_pos(): void
    {
        ['cashier' => $cashier, 'store' => $store] = $this->setupWarung();
        $reservation = $this->reservation($cashier);

        $this->assertSame('booked', $reservation->status);
        $this->assertSame($store->id, $reservation->store_id);
        $this->assertEquals(50000, $reservation->dp_amount);
        $this->assertNotNull($reservation->dp_paid_at);
        $this->assertStringStartsWith('RSV-', $reservation->code);

        $this->actingAs($cashier)->get('/reservasi')->assertOk()->assertSee('Bu Sari')->assertSee('Rp 50.000', false);
        $this->actingAs($cashier)->get('/kasir')->assertOk()->assertSee($reservation->code)->assertSee('useReservation', false);

        // DP non-tunai wajib menyebut bank/provider penerima.
        $this->actingAs($cashier)->post('/reservasi', [
            'customer_name' => 'Pak Budi', 'reserved_date' => today()->toDateString(), 'reserved_time' => '12:00', 'guests' => 2,
            'dp_amount' => 20000, 'dp_method' => 'transfer',
        ])->assertSessionHasErrors('dp_provider');
    }

    public function test_dp_reduces_the_bill_and_is_counted_once_in_cash_closing(): void
    {
        ['cashier' => $cashier, 'menu' => $menu, 'spv' => $spv] = $this->setupWarung();
        $reservation = $this->reservation($cashier);

        // Total 5 × 20.000 = 100.000; DP 50.000; tunai diterima 60.000 → kembalian 10.000.
        $this->checkout($cashier, [
            'items' => [['id' => $menu->id, 'qty' => 5]], 'reservation_id' => $reservation->id,
            'payments' => [['method' => 'cash', 'amount' => 60000]],
        ])->assertOk();

        $transaction = Transaction::with('payments')->latest('id')->firstOrFail();
        $this->assertEquals(100000, $transaction->total);
        $this->assertEquals(10000, $transaction->change_amount);
        $this->assertSame('cash', $transaction->payment_method);
        $this->assertSame(['dp', 'cash'], $transaction->payments->pluck('method')->all());
        $this->assertEquals(50000, $transaction->payments->firstWhere('method', 'dp')->amount);
        $this->assertSame($reservation->code, $transaction->payments->firstWhere('method', 'dp')->provider);

        $reservation->refresh();
        $this->assertSame('completed', $reservation->status);
        $this->assertSame($transaction->id, $reservation->transaction_id);
        $this->assertEquals(50000, $reservation->dp_used);

        // Kas: DP tunai 50.000 hari ini + penjualan tunai net 50.000 (DP tidak dihitung dua kali).
        $this->actingAs($cashier)->post('/kasir/tutup-harian', ['opening_cash' => 0, 'actual_cash' => 100000, 'approval_pin' => '1234'])->assertSessionHasNoErrors();
        $closing = DB::table('cashier_closings')->first();
        $this->assertEquals(50000, $closing->cash_sales);
        $this->assertEquals(50000, $closing->cash_reservation_dp);
        $this->assertEquals(100000, $closing->expected_cash);
        $this->assertEquals(0, $closing->difference);

        // Reservasi yang sudah selesai tidak dapat dipakai lagi.
        $this->checkout($cashier, [
            'items' => [['id' => $menu->id, 'qty' => 1]], 'reservation_id' => $reservation->id,
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertStatus(422);

        // Pembatalan transaksi membuka kembali reservasi beserta DP-nya.
        $this->actingAs($spv)->deleteJson("/transaksi/{$transaction->id}", ['reason' => 'Salah input meja'])->assertRedirect();
        $reservation->refresh();
        $this->assertSame('arrived', $reservation->status);
        $this->assertNull($reservation->transaction_id);
        $this->assertEquals(0, $reservation->dp_used);
    }

    public function test_dp_can_cover_the_whole_bill(): void
    {
        ['cashier' => $cashier, 'menu' => $menu] = $this->setupWarung();
        $reservation = $this->reservation($cashier, ['dp_method' => 'qris', 'dp_provider' => 'QRIS BCA']);

        $this->checkout($cashier, ['items' => [['id' => $menu->id, 'qty' => 2]], 'reservation_id' => $reservation->id, 'payments' => []])->assertOk();

        $transaction = Transaction::with('payments')->latest('id')->firstOrFail();
        $this->assertEquals(40000, $transaction->total);
        $this->assertSame('qris', $transaction->payment_method);
        $this->assertSame(['dp'], $transaction->payments->pluck('method')->all());
        $this->assertEquals(40000, $reservation->fresh()->dp_used);
        $this->actingAs($cashier)->get('/reservasi')->assertSee('Sisa DP Rp 10.000', false);
    }

    public function test_pending_bill_keeps_the_reservation_until_paid(): void
    {
        ['cashier' => $cashier, 'menu' => $menu] = $this->setupWarung();
        $reservation = $this->reservation($cashier);
        $order = ['items' => [['id' => $menu->id, 'qty' => 3]], 'service_type' => 'dine_in', 'table_number' => 'A-07', 'reservation_id' => $reservation->id];

        $this->actingAs($cashier)->postJson('/kasir/pending', $order)->assertOk();
        $bill = Transaction::where('status', 'pending')->firstOrFail();
        $this->assertSame($bill->id, $reservation->fresh()->transaction_id);
        $this->assertSame('arrived', $reservation->fresh()->status);
        $this->actingAs($cashier)->get('/kasir')->assertOk()->assertSee('"pending_id":'.$bill->id, false);

        // Selama masih terhubung ke open bill, reservasi tidak dapat dibatalkan.
        $this->actingAs($cashier)->patch("/reservasi/{$reservation->id}/status", ['status' => 'cancelled', 'reason' => 'Batal'])->assertStatus(422);

        $this->actingAs($cashier)->postJson('/kasir/checkout', $order + ['pending_transaction_id' => $bill->id, 'payments' => [['method' => 'cash', 'amount' => 10000]]])->assertOk();
        $this->assertSame('completed', $bill->fresh()->status);
        $this->assertSame('completed', $reservation->fresh()->status);
    }

    public function test_cancelled_reservation_with_refund_reduces_expected_cash(): void
    {
        ['cashier' => $cashier] = $this->setupWarung();
        $refunded = $this->reservation($cashier);
        $forfeited = $this->reservation($cashier, ['customer_name' => 'Pak Andi', 'dp_amount' => 30000]);

        $this->actingAs($cashier)->patch("/reservasi/{$refunded->id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('reason');
        $this->actingAs($cashier)->patch("/reservasi/{$refunded->id}/status", ['status' => 'cancelled', 'reason' => 'Acara batal', 'refund_dp' => 1])->assertSessionHasNoErrors();
        $this->actingAs($cashier)->patch("/reservasi/{$forfeited->id}/status", ['status' => 'no_show'])->assertSessionHasNoErrors();

        $this->assertTrue($refunded->fresh()->dp_refunded);
        $this->assertSame('no_show', $forfeited->fresh()->status);
        $this->assertFalse($forfeited->fresh()->dp_refunded);
        // 50.000 + 30.000 diterima, 50.000 dikembalikan → 30.000 tetap di laci.
        $this->actingAs($cashier)->get('/kasir/tutup-harian')->assertOk()->assertSee('Rp 30.000', false);
    }

    public function test_reservations_are_isolated_per_store(): void
    {
        ['tenant' => $tenant, 'otherStore' => $otherStore, 'cashier' => $cashier] = $this->setupWarung();
        $foreign = Reservation::create([
            'tenant_id' => $tenant->id, 'store_id' => $otherStore->id, 'code' => 'RSV-LAIN', 'customer_name' => 'Tamu Warung B',
            'reserved_at' => now(), 'guests' => 2, 'status' => 'booked',
        ]);

        $this->actingAs($cashier)->get('/reservasi')->assertOk()->assertDontSee('Tamu Warung B');
        $this->actingAs($cashier)->patch("/reservasi/{$foreign->id}/status", ['status' => 'arrived'])->assertNotFound();
        $this->actingAs($cashier)->postJson('/kasir/checkout', [
            'items' => [['id' => Product::first()->id, 'qty' => 1]], 'service_type' => 'takeaway', 'reservation_id' => $foreign->id,
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertNotFound();
    }

    private function outletWorkbook(array $extraMatang = [], bool $withStock = false): UploadedFile
    {
        $book = new Spreadsheet;
        $matang = $book->getActiveSheet()->setTitle('MATANG');
        $matang->fromArray([
            ['NO', 'NAMA PRODUK', 'SATUAN', 'KATEGORI', 'SKU', 'BARCODE', 'NOMINAL OFLINE', 'NOMINAL ONLINE', 'MIN STOK', ...($withStock ? ['STOK'] : [])],
            [null],
            [1, 'Alpukat', 'pcs', 'SNACK', 'SN-01', null, 20000, 28571.42857, 5, ...($withStock ? [12] : [])],
            [2, 'Ayam Bakar Negeri Size 1', 'pcs', 'MAKANAN', 'BA-04', null, 12000, 17142.85714, 20],
            [3, 'Ayam Bakar Negeri Size 2', 'pcs', 'MAKANAN', 'BA-04', null, 18000, 25714.28571, 20],
            [4, 'Ayam Kampung Besar Utuh', 'pcs', 'MAKANAN', 'BA-03', null, 100000, 142857.1429, 5],
            ...$extraMatang,
        ]);
        $mentah = $book->createSheet()->setTitle('MENTAH');
        $mentah->fromArray([
            ['NO', 'NAMA PRODUK', 'SATUAN', 'KATEGORI', 'SKU', 'BARCODE', 'MIN STOK'],
            [null],
            [1, 'Ayam Kampung Utuh', 'ekr', 'BASAH', 'BA-03', null, null],
            [2, 'Ayam Karkas (20 potong / pack)', 'pcs', 'BASAH', 'BA-04', null, 40],
            [3, '', 'pcs', 'BASAH', 'BA-99', null, null],
        ]);
        $book->createSheet()->setTitle('SUPPORT')->fromArray([
            ['NO', 'BAHAN BAKU', 'SATUAN', 'KATEGORI', 'SKU'], [null], [1, 'Kertas Thermal', 'roll', 'SUPPORT', 'SU-10'],
        ]);
        $book->createSheet()->setTitle('CV')->fromArray([
            ['NO', 'ITEM', 'SATUAN', 'KATEGORI', 'SKU', 'MIN STOCK'], [null], [1, 'Ayam Kampung', 'Ekor', 'PRODUKSI', 'PRO-01', 50],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'outlet').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'POS MENU ALL OUTLET & SKU.xlsx', null, null, true);
    }

    public function test_outlet_workbook_imports_menus_and_ingredients_with_shared_sku(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'store' => $store] = $this->setupWarung();

        $this->actingAs($admin)->post('/produk/import', ['file' => $this->outletWorkbook()])
            ->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '4 menu baru') && str_contains($message, '4 bahan baku baru'))
            ->assertSessionHas('import_notes', fn ($notes) => str_contains($notes[0], 'MENTAH baris 5'));

        $menus = Product::where('tenant_id', $tenant->id)->where('product_type', 'menu')->where('sku', '!=', 'AB-1')->orderBy('sku')->get();
        $this->assertSame(['BA-03', 'BA-04-1', 'BA-04-2', 'SN-01'], $menus->pluck('sku')->all());
        $this->assertSame(['BA-03', 'BA-04', 'BA-04', 'SN-01'], $menus->pluck('ingredient_sku')->all());
        $size1 = $menus->firstWhere('name', 'Ayam Bakar Negeri Size 1');
        $this->assertEquals(12000, $size1->selling_price);
        $this->assertEquals(17143, $size1->online_selling_price);
        $this->assertEquals(20, $size1->minimum_stock);
        $this->assertSame('Makanan', $size1->category->name);

        // Menu dan bahan baku boleh memakai SKU yang sama (BA-03 matang & mentah).
        $rawChicken = Product::where('product_type', 'ingredient')->where('sku', 'BA-03')->firstOrFail();
        $this->assertSame('Ayam Kampung Utuh', $rawChicken->name);
        $this->assertSame('ekr', $rawChicken->unit);
        $this->assertSame('Basah', $rawChicken->category->name);
        $this->assertSame(['BA-03', 'BA-04', 'PRO-01', 'SU-10'], Product::where('product_type', 'ingredient')->orderBy('sku')->pluck('sku')->all());
        $this->assertEquals(50, Product::where('sku', 'PRO-01')->value('minimum_stock'));
        $this->assertSame('bi-cookie', Category::where('name', 'Snack')->value('icon'));

        // Stok cabang aktif tersedia (0) untuk menu dan bahan baku baru.
        $this->assertEquals(0, DailyMenuStock::where('store_id', $store->id)->where('product_id', $size1->id)->value('quantity'));
        $this->assertTrue(ProductStock::where('store_id', $store->id)->where('product_id', $rawChicken->id)->exists());
        $this->actingAs($admin)->get('/produk?q=BA-04')->assertOk()->assertSee('Ayam Bakar Negeri Size 2')->assertSee('SKU bahan BA-04')->assertDontSee('Alpukat');
    }

    public function test_reimporting_outlet_workbook_updates_without_duplicates_and_sets_stock(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'store' => $store] = $this->setupWarung();
        $this->actingAs($admin)->post('/produk/import', ['file' => $this->outletWorkbook()])->assertRedirect();
        $size2Id = Product::where('name', 'Ayam Bakar Negeri Size 2')->value('id');
        Product::where('sku', 'BA-03')->where('product_type', 'ingredient')->update(['minimum_stock' => 7]);

        // Baris baru dengan SKU bersama + kolom stok; urutan menu lama tetap dikenali lewat nama.
        $this->actingAs($admin)->post('/produk/import', ['file' => $this->outletWorkbook([[5, 'Ayam Goreng Negeri Size 1', 'pcs', 'MAKANAN', 'BA-04', null, 12000, 17142.85714, 20]], true)])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 menu baru') && str_contains($message, '4 menu diperbarui') && str_contains($message, 'stok 1 produk'));

        $this->assertSame(5, Product::where('tenant_id', $tenant->id)->where('product_type', 'menu')->where('sku', '!=', 'AB-1')->count());
        $this->assertSame('BA-04-2', Product::find($size2Id)->sku);
        $this->assertSame('BA-04-3', Product::where('name', 'Ayam Goreng Negeri Size 1')->value('sku'));
        // MIN STOK kosong di sheet MENTAH tidak menghapus nilai yang sudah ada.
        $this->assertEquals(7, Product::where('sku', 'BA-03')->where('product_type', 'ingredient')->value('minimum_stock'));

        $avocado = Product::where('name', 'Alpukat')->firstOrFail();
        $this->assertEquals(12, DailyMenuStock::where('store_id', $store->id)->where('product_id', $avocado->id)->whereDate('stock_date', today())->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $avocado->id, 'activity' => 'stock_opname', 'quantity' => 12, 'reference' => 'IMPORT-'.today()->format('Ymd')]);
        $this->assertDatabaseHas('stock_counts', ['product_id' => $avocado->id, 'actual_quantity' => 12]);
    }

    public function test_legacy_product_template_still_imports(): void
    {
        ['admin' => $admin] = $this->setupWarung();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Produk')->fromArray([
            ['SKU', 'Barcode', 'Nama', 'Jenis', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Normal', 'Harga Online', 'Stok Minimum'],
            ['GULA', null, 'Gula Pasir', 'ingredient', 'Kering', 'kg', 15000, 0, 0, 3],
            ['GULA', null, 'Es Gula Aren', 'menu', 'Minuman', 'gelas', 3000, 8000, 9000, 5],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'legacy').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])
            ->assertSessionHas('success', '2 produk berhasil diimpor/diperbarui.');
        $this->assertSame(['ingredient', 'menu'], Product::where('sku', 'GULA')->orderBy('product_type')->pluck('product_type')->all());
    }

    /** Unduh template, isi satu baris data seperti pengguna, lalu impor kembali file yang sama. */
    private function filledTemplate(User $admin, string $format, string $sheet, array $row): UploadedFile
    {
        $response = $this->actingAs($admin)->get("/produk/import/template/{$format}")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $book->getSheetByName($sheet)->fromArray($row, null, 'A2');
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, "template-{$format}.xlsx", null, null, true);
    }

    public function test_import_templates_document_columns_and_import_back_cleanly(): void
    {
        ['admin' => $admin] = $this->setupWarung();

        $this->actingAs($admin)->get('/produk')->assertOk()
            ->assertSee('Download template')->assertSee('/produk/import/template/outlet', false)->assertSee('/produk/import/template/standard', false)
            ->assertSee('NOMINAL OFLINE')->assertSee('Pilihan Harga')->assertSee('data-max-mb="5"', false);
        $this->actingAs($admin)->get('/produk/import/template/lain')->assertNotFound();

        // Workbook outlet: sheet Petunjuk berisi contoh kolom, tetapi tidak ikut terimpor.
        $outlet = $this->filledTemplate($admin, 'outlet', 'MATANG', [1, 'Es Jeruk', 'gelas', 'MINUMAN', 'MN-77', null, 7000, 10000, 5, 12]);
        $book = IOFactory::load($outlet->getRealPath());
        $this->assertSame(['Petunjuk', 'MATANG', 'MENTAH', 'SUPPORT', 'CV'], $book->getSheetNames());
        $this->assertSame(['NO', 'NAMA PRODUK', 'SATUAN', 'KATEGORI', 'SKU', 'BARCODE', 'MIN STOK', 'STOK'], $book->getSheetByName('MENTAH')->rangeToArray('A1:H1')[0]);
        $this->actingAs($admin)->post('/produk/import', ['file' => $outlet])
            ->assertSessionHas('success', 'Impor workbook outlet selesai: 1 menu baru, 0 menu diperbarui; stok 1 produk disetel.');
        $this->assertEquals(10000, Product::where('sku', 'MN-77')->value('online_selling_price'));
        $this->assertFalse(Product::where('name', 'Ayam Bakar Negeri Size 1')->exists());

        // Format standar: kolom sama dengan Download Excel.
        $standard = $this->filledTemplate($admin, 'standard', 'Produk', ['GULA-01', null, 'Gula Pasir', 'ingredient', 'Kering', 'kg', 15000, 0, 0, 3, null, 8]);
        $this->assertSame(['Petunjuk', 'Produk', 'Harga Warung', 'Pilihan Harga'], IOFactory::load($standard->getRealPath())->getSheetNames());
        $this->actingAs($admin)->post('/produk/import', ['file' => $standard])->assertSessionHas('success', '1 produk berhasil diimpor/diperbarui.');
        $this->assertSame('ingredient', Product::where('sku', 'GULA-01')->value('product_type'));
    }
}
