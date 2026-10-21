<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Temuan audit seluruh modul per role, 21 Oktober 2026. */
class October21AuditTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Audit', 'slug' => 'warung-audit']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Melati', 'code' => 'MLT', 'is_active' => true]);
        $other = Store::create(['tenant_id' => $tenant->id, 'name' => 'Kenanga', 'code' => 'KNG', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Admin', 'email' => 'admin@audit.test', 'role' => 'superadmin', 'is_active' => true, 'password' => 'password']);
        $cashier = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'kasir@audit.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);
        $raw = Product::create(['tenant_id' => $tenant->id, 'name' => 'Ayam Mentah', 'product_type' => 'ingredient', 'sku' => 'RAW-1', 'unit' => 'pcs', 'purchase_price' => 10000, 'selling_price' => 0, 'minimum_stock' => 0, 'is_active' => true]);
        $menu = Product::create(['tenant_id' => $tenant->id, 'name' => 'Ayam Bakar', 'product_type' => 'menu', 'sku' => 'MN-1', 'unit' => 'pcs', 'purchase_price' => 8000, 'selling_price' => 20000, 'online_selling_price' => 20000, 'minimum_stock' => 0, 'is_active' => true]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 10]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 10]);

        return compact('tenant', 'store', 'other', 'admin', 'cashier', 'raw', 'menu');
    }

    public function test_inventory_page_survives_archived_product_in_production_history(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'raw' => $raw, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($cashier)->post('/gudang/production', [
            'ingredient_product_id' => $raw->id, 'menu_product_id' => $menu->id, 'ingredient_quantity' => 1, 'output_quantity' => 2,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete("/produk/{$menu->id}")->assertSessionHasNoErrors();

        $this->actingAs($cashier)->get('/gudang')->assertOk()->assertSee('Ayam Bakar');
    }

    public function test_paid_purchase_can_be_corrected_to_a_lower_total(): void
    {
        ['admin' => $admin, 'raw' => $raw] = $this->setupWarung();
        $payload = ['supplier_name' => 'Pasar', 'product_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 10000, 'purchased_at' => today()->toDateString(), 'status' => 'received', 'payment_status' => 'paid'];
        // Field DP tersembunyi masih berisi nilai lama saat status Lunas.
        $this->actingAs($admin)->post('/pembelian', $payload + ['dp_amount' => 500000])->assertSessionHasNoErrors();
        $purchase = Purchase::firstOrFail();
        $this->actingAs($admin)->put("/pembelian/{$purchase->id}", ['quantity' => 5, 'dp_amount' => 100000] + $payload)->assertSessionHasNoErrors();
        $this->assertEquals(50000, $purchase->fresh()->total);
        $this->assertEquals(50000, $purchase->fresh()->dp_amount);

        // Status DP tetap diperiksa.
        $this->actingAs($admin)->put("/pembelian/{$purchase->id}", ['payment_status' => 'dp', 'dp_amount' => 60000, 'quantity' => 5] + $payload)
            ->assertSessionHasErrors();
    }

    public function test_product_import_restores_archived_category_and_keeps_barcode_owner(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $menu->update(['barcode' => 'BR-1']);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Gorengan', 'color' => '#000000']);
        $category->delete();

        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Produk')->fromArray([
            ['SKU', 'Barcode', 'Nama', 'Jenis', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Normal', 'Harga Online', 'Stok Minimum', 'Stok Awal'],
            ['BKW-1', 'BR-1', 'Bakwan', 'menu', 'Gorengan', 'pcs', 1000, 3000, 3500, 5, 12],
            ['TPG-1', null, 'Tepung', 'Bahan Baku', 'Gorengan', 'kg', 9000, 0, 0, 1, 3],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'audit').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($admin)->post('/produk/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'barcode BR-1 sudah dipakai Ayam Bakar'));
        $this->assertFalse($category->fresh()->trashed());
        $this->assertNull(Product::where('sku', 'BKW-1')->value('barcode'));
        $this->assertSame('ingredient', Product::where('sku', 'TPG-1')->value('product_type'));
    }

    public function test_cash_payment_does_not_keep_a_bank_provider(): void
    {
        ['cashier' => $cashier, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($cashier)->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway',
            'payments' => [['method' => 'cash', 'provider' => 'QRIS BCA', 'amount' => 20000]],
        ])->assertOk();
        $this->assertNull(TransactionPayment::firstOrFail()->provider);
    }

    public function test_member_history_totals_ignore_mutation_filter(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'name' => 'Budi', 'member_code' => 'M-1', 'qr_code' => 'Q-1', 'deposit_balance' => 30000]);
        DB::table('deposit_transactions')->insert([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'member_id' => $member->id, 'user_id' => $admin->id,
            'type' => 'credit', 'payment_method' => 'cash', 'amount' => 30000, 'balance_after' => 30000,
            'description' => 'Top up', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get("/member/{$member->id}/riwayat?type=debit")->assertOk()
            ->assertViewHas('summary', fn ($summary) => (float) $summary['topups'] === 30000.0);
    }

    public function test_account_of_inactive_branch_cannot_be_reactivated(): void
    {
        ['tenant' => $tenant, 'other' => $other, 'admin' => $admin] = $this->setupWarung();
        $user = User::create(['tenant_id' => $tenant->id, 'store_id' => $other->id, 'name' => 'Kasir KNG', 'email' => 'kng@audit.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);
        $this->actingAs($admin)->patch("/pengaturan/pengguna/{$user->id}/status", ['is_active' => 0])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch("/pengaturan/cabang/{$other->id}/status", ['is_active' => 0])->assertSessionHasNoErrors();

        $this->actingAs($admin)->from('/pengaturan')->patch("/pengaturan/pengguna/{$user->id}/status", ['is_active' => 1])
            ->assertRedirect('/pengaturan')->assertSessionHasErrors();
        $this->assertTrue(User::withTrashed()->find($user->id)->trashed());
    }

    public function test_deleted_role_key_can_be_reused(): void
    {
        ['admin' => $admin] = $this->setupWarung();
        $payload = ['key' => 'kasir_senior', 'name' => 'Kasir Senior', 'modules' => ['pos']];
        $this->actingAs($admin)->post('/pengaturan/role', $payload)->assertSessionHasNoErrors();
        $role = Role::where('key', 'kasir_senior')->firstOrFail();
        $this->actingAs($admin)->delete("/pengaturan/role/{$role->id}")->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/pengaturan/role', ['name' => 'Kasir Senior Baru', 'modules' => ['pos', 'members']] + $payload)->assertSessionHasNoErrors();
        $this->assertSame(['pos', 'members'], Role::where('key', 'kasir_senior')->firstOrFail()->modules);
    }
}
