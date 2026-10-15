<?php

namespace Tests\Feature;

use App\Models\DailyMenuStock;
use App\Models\InventoryDailyRecord;
use App\Models\InventoryEditLog;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class August17RevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Agustus', 'slug' => 'warung-agustus']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Ops Admin', 'email' => 'ops17@test.id',
            'role' => User::OPS_ADMIN, 'is_active' => true, 'password' => 'password',
        ]);
        $cashier = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'kasir17@test.id',
            'role' => User::CASHIER, 'is_active' => true, 'password' => 'password',
        ]);
        $supervisor = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'SPV', 'email' => 'spv17@test.id',
            'role' => User::SPV, 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('1717'),
        ]);
        $owner = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Superadmin', 'email' => 'owner17@test.id',
            'role' => User::SUPERADMIN, 'is_active' => true, 'password' => 'password',
        ]);

        return compact('tenant', 'store', 'admin', 'cashier', 'supervisor', 'owner');
    }

    public function test_inventory_carries_opening_stock_and_supports_inline_usage_notes_reprocess_and_opname_reset(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin, 'cashier' => $cashier] = $this->setupWarung();
        $raw = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Gula', 'product_type' => 'ingredient', 'sku' => 'GULA',
            'unit' => 'kg', 'purchase_price' => 15000, 'selling_price' => 0, 'minimum_stock' => 2,
        ]);
        $source = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Ayam Goreng', 'product_type' => 'menu', 'sku' => 'AYM-G',
            'unit' => 'porsi', 'purchase_price' => 10000, 'selling_price' => 20000, 'minimum_stock' => 2,
        ]);
        $target = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Nasi Goreng Ayam', 'product_type' => 'menu', 'sku' => 'NAS-AYM',
            'unit' => 'porsi', 'purchase_price' => 12000, 'selling_price' => 25000, 'minimum_stock' => 2,
        ]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 10]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $source->id, 'stock_date' => today()->subDay(), 'quantity' => 8]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $target->id, 'stock_date' => today()->subDay(), 'quantity' => 3]);

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->get('/gudang')->assertOk()->assertSeeText('Stok terpakai')->assertSeeText('Diproses ulang');
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $store->id, 'product_id' => $source->id, 'stock_date' => today()->toDateString(), 'quantity' => 8]);

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->patchJson("/gudang/stock/{$raw->id}", [
            'opening_quantity' => 12,
            'used_quantity' => 3,
            'notes' => 'Dipakai untuk bumbu yang tidak punya SKU olahan',
        ])->assertOk()->assertJsonPath('data.quantity', 9);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $raw->id, 'quantity' => 9]);
        $this->assertDatabaseHas('inventory_daily_records', ['product_id' => $raw->id, 'opening_quantity' => 12, 'used_quantity' => 3, 'opening_is_manual' => true]);
        $this->assertSame('Dipakai untuk bumbu yang tidak punya SKU olahan', InventoryDailyRecord::where('product_id', $raw->id)->value('notes'));

        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->patchJson("/gudang/stock/{$raw->id}", ['opening_quantity' => 20])->assertForbidden();

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->post('/gudang/reprocess', [
            'source_product_id' => $source->id,
            'target_product_id' => $target->id,
            'source_quantity' => 2,
            'output_quantity' => 1,
            'notes' => 'Ayam hancur menjadi nasi goreng',
        ])->assertRedirect();
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $source->id, 'stock_date' => today()->toDateString(), 'quantity' => 6]);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $target->id, 'stock_date' => today()->toDateString(), 'quantity' => 4]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $source->id, 'activity' => 'reprocess_out', 'quantity' => -2]);

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->post('/gudang/count', [
            'product_id' => $source->id, 'actual_quantity' => 5, 'notes' => 'Selisih satu porsi',
        ])->assertRedirect();
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $source->id, 'stock_date' => today()->toDateString(), 'quantity' => 5]);
        $this->assertDatabaseHas('stock_counts', ['product_id' => $source->id, 'expected_quantity' => 6, 'actual_quantity' => 5]);
    }

    public function test_any_inventory_user_can_live_edit_menu_production_consumption_and_reprocessed(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'cashier' => $cashier] = $this->setupWarung();
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Ayam Bakar', 'product_type' => 'menu', 'sku' => 'AYM-B',
            'unit' => 'porsi', 'purchase_price' => 10000, 'selling_price' => 20000, 'minimum_stock' => 2,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today()->subDay(), 'quantity' => 5]);
        $as = fn () => $this->actingAs($cashier)->withSession(['store_id' => $store->id]);

        $as()->get('/gudang')->assertOk()->assertSee('name="produced_quantity"', false)
            ->assertSee('name="consumption_quantity"', false)->assertSee('name="reprocessed_quantity"', false);

        $as()->patchJson("/gudang/stock/{$menu->id}", ['produced_quantity' => 10])->assertOk()->assertJsonPath('data.quantity', 15);
        $as()->patchJson("/gudang/stock/{$menu->id}", ['consumption_quantity' => 2])->assertOk()->assertJsonPath('data.quantity', 13);
        $as()->patchJson("/gudang/stock/{$menu->id}", ['reprocessed_quantity' => 3])->assertOk()->assertJsonPath('data.quantity', 10);
        // Koreksi turun mengembalikan selisihnya ke stok.
        $as()->patchJson("/gudang/stock/{$menu->id}", ['produced_quantity' => 8, 'consumption_quantity' => 1])->assertOk()->assertJsonPath('data.quantity', 9);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'stock_date' => today()->toDateString(), 'quantity' => 9]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $menu->id, 'activity' => 'production', 'quantity' => -2]);

        $page = $as()->get('/gudang')->assertOk();
        $page->assertSee('name="produced_quantity" value="8"', false)
            ->assertSee('name="consumption_quantity" value="1"', false)
            ->assertSee('name="reprocessed_quantity" value="3"', false);

        // Riwayat: setiap perubahan tercatat dengan pengguna, kolom, dan nilai sebelum/sesudah.
        $this->assertSame(5, InventoryEditLog::where('product_id', $menu->id)->count());
        $this->assertDatabaseHas('inventory_edit_logs', ['product_id' => $menu->id, 'user_id' => $cashier->id, 'field' => 'produced_quantity', 'old_value' => '10', 'new_value' => '8']);
        $this->assertDatabaseHas('inventory_edit_logs', ['product_id' => $menu->id, 'user_id' => $cashier->id, 'field' => 'consumption_quantity', 'old_value' => '0', 'new_value' => '2']);
        $page->assertSeeText('Riwayat edit stok')->assertSeeText('Kasir')->assertSeeText('Tambahan olahan')->assertSeeText('Diproses ulang');
        // Nilai yang sama tidak menambah riwayat.
        $as()->patchJson("/gudang/stock/{$menu->id}", ['reprocessed_quantity' => 3, 'notes' => 'Sisa siang'])->assertOk();
        $this->assertSame(6, InventoryEditLog::where('product_id', $menu->id)->count());
        $this->assertDatabaseHas('inventory_edit_logs', ['product_id' => $menu->id, 'field' => 'notes', 'old_value' => null, 'new_value' => 'Sisa siang']);
        $as()->get('/gudang?riwayat_tanggal='.today()->subDay()->toDateString())->assertOk()->assertSeeText('Belum ada edit stok');

        $as()->patchJson("/gudang/stock/{$menu->id}", ['consumption_quantity' => 50])->assertStatus(422);
        $this->assertSame(6, InventoryEditLog::where('product_id', $menu->id)->count());
        $raw = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Tepung', 'product_type' => 'ingredient', 'sku' => 'TPG',
            'unit' => 'kg', 'purchase_price' => 1000, 'selling_price' => 0, 'minimum_stock' => 1,
        ]);
        $as()->patchJson("/gudang/stock/{$raw->id}", ['produced_quantity' => 1])->assertStatus(422);
    }

    public function test_expense_categories_are_fixed_and_member_database_can_be_exported(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin, 'owner' => $owner] = $this->setupWarung();
        Member::create([
            'tenant_id' => $tenant->id, 'member_code' => 'MBR-017', 'qr_code' => 'QR-017', 'name' => 'Rani',
            'phone' => '081200000017', 'domicile' => 'Bandung', 'birth_date' => '1997-08-17', 'deposit_balance' => 50000,
        ]);

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->post('/pengeluaran', [
            'category' => 'Waste', 'description' => 'Bahan rusak', 'amount' => 25000, 'expense_date' => today()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('expenses', ['category' => 'Waste', 'amount' => 25000]);
        $this->actingAs($admin)->withSession(['store_id' => $store->id])->post('/pengeluaran', [
            'category' => 'Operasional', 'description' => 'Kategori lama', 'amount' => 1000, 'expense_date' => today()->toDateString(),
        ])->assertSessionHasErrors('category');

        $page = $this->actingAs($admin)->withSession(['store_id' => $store->id])->get('/member');
        $page->assertOk()->assertSeeText('Download Excel');
        $export = $this->actingAs($admin)->withSession(['store_id' => $store->id])->get('/member/export');
        $export->assertOk()->assertDownload('database-member-'.now()->format('Ymd').'.xlsx');
        $this->assertStringStartsWith('PK', $export->streamedContent());
        $this->actingAs($admin)->withSession(['store_id' => $store->id])->get('/kasir')->assertOk()->assertSeeText('Pilih bank / provider');
        $this->actingAs($owner)->withSession(['store_id' => $store->id])->get('/pengaturan')->assertOk()->assertSeeText('Preview struk customer 58 mm');
    }

    public function test_replacement_transaction_requires_manager_or_supervisor_pin(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Menu Pengganti', 'product_type' => 'menu', 'sku' => 'RET-17',
            'unit' => 'porsi', 'purchase_price' => 8000, 'selling_price' => 18000, 'minimum_stock' => 1,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 5]);
        $payload = [
            'items' => [['id' => $menu->id, 'qty' => 1]],
            'service_type' => 'takeaway',
            'transaction_type' => 'replacement',
        ];

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', $payload)->assertStatus(422);
        $this->actingAs($admin)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', $payload + ['approval_pin' => '1717'])->assertOk();

        $transaction = Transaction::firstOrFail();
        $this->assertSame('replacement', $transaction->transaction_type);
        $this->assertEquals(0, $transaction->total);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 4]);
    }
}
