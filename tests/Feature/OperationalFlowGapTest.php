<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalFlowGapTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Flow', 'slug' => 'warung-flow']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Admin', 'email' => 'admin@flow.test',
            'role' => User::SUPERADMIN, 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('1111'),
        ]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan', 'color' => '#78978a']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Nasi', 'product_type' => 'menu',
            'sku' => 'NS-1', 'unit' => 'porsi', 'purchase_price' => 4000, 'selling_price' => 10000,
            'online_selling_price' => 12000, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        DailyMenuStock::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id,
            'stock_date' => today(), 'quantity' => 20,
        ]);

        return compact('tenant', 'store', 'admin', 'category', 'menu');
    }

    public function test_cash_report_uses_net_receipt_after_change(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $admin->id,
            'invoice_no' => 'TRX-NET', 'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real',
            'service_type' => 'takeaway', 'subtotal' => 10000, 'discount' => 0, 'total' => 10000,
            'payment_method' => 'cash', 'paid_amount' => 15000, 'change_amount' => 5000, 'transacted_at' => now(),
        ]);
        $transaction->payments()->create(['method' => 'cash', 'amount' => 15000]);

        $data = app(TransactionReportExporter::class)->data($tenant->id, $store->id, now()->startOfDay(), now()->endOfDay(), 1);

        $this->assertSame(10000.0, (float) $data['payments']->firstWhere('payment_method', 'cash')->total);
        $this->assertSame(10000.0, (float) $data['sales']);
    }

    public function test_open_bill_is_updated_in_place_and_can_be_cancelled(): void
    {
        ['store' => $store, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $payload = ['items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'dine_in', 'table_number' => 'A1'];
        $this->actingAs($admin)->withSession(['store_id' => $store->id])->postJson('/kasir/pending', $payload)->assertOk();
        $pending = Transaction::where('status', 'pending')->firstOrFail();

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->postJson('/kasir/pending', array_merge($payload, [
            'items' => [['id' => $menu->id, 'qty' => 2]],
            'pending_transaction_id' => $pending->id,
        ]))->assertOk();

        $this->assertSame(1, Transaction::where('status', 'pending')->count());
        $this->assertDatabaseHas('transaction_items', ['transaction_id' => $pending->id, 'quantity' => 2]);
        $this->actingAs($admin)->withSession(['store_id' => $store->id])
            ->delete("/kasir/pending/{$pending->id}", ['reason' => 'Tamu batal'])
            ->assertRedirect();
        $this->assertDatabaseHas('transactions', ['id' => $pending->id, 'status' => 'voided', 'cancel_reason' => 'Tamu batal']);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 20]);
    }

    public function test_old_void_restores_the_current_operational_stock(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        DailyMenuStock::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id,
            'stock_date' => today()->subDay(), 'quantity' => 4,
        ]);
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $admin->id,
            'invoice_no' => 'TRX-YESTERDAY', 'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real',
            'service_type' => 'takeaway', 'subtotal' => 10000, 'discount' => 0, 'total' => 10000,
            'payment_method' => 'cash', 'paid_amount' => 10000, 'change_amount' => 0, 'transacted_at' => now()->subDay(),
        ]);
        $transaction->items()->create([
            'product_id' => $menu->id, 'product_name' => $menu->name, 'quantity' => 1,
            'price' => 10000, 'cost' => 4000, 'subtotal' => 10000,
        ]);

        $this->actingAs($admin)->withSession(['store_id' => $store->id])
            ->delete("/transaksi/{$transaction->id}", ['reason' => 'Salah transaksi lama', 'approval_pin' => '1111'])
            ->assertRedirect();

        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'stock_date' => today()->toDateString(), 'quantity' => 21]);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'stock_date' => today()->subDay()->toDateString(), 'quantity' => 4]);
    }

    public function test_cashier_closing_reconciles_cash_and_rejects_other_store_pin(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        $cashier = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'cashier@flow.test',
            'role' => User::CASHIER, 'is_active' => true, 'password' => 'password',
        ]);
        $otherStore = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang', 'code' => 'CBG', 'is_active' => true]);
        User::create([
            'tenant_id' => $tenant->id, 'store_id' => $otherStore->id, 'name' => 'SPV Lain', 'email' => 'other@flow.test',
            'role' => User::SPV, 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('2222'),
        ]);
        $localSpv = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'SPV Lokal', 'email' => 'local@flow.test',
            'role' => User::SPV, 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('3333'),
        ]);
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashier->id,
            'invoice_no' => 'TRX-CLOSE', 'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real',
            'service_type' => 'takeaway', 'subtotal' => 10000, 'discount' => 0, 'total' => 10000,
            'payment_method' => 'cash', 'paid_amount' => 15000, 'change_amount' => 5000, 'transacted_at' => now(),
        ]);
        $transaction->payments()->create(['method' => 'cash', 'amount' => 15000]);
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'M-1', 'qr_code' => 'Q-1', 'name' => 'Member']);
        DB::table('deposit_transactions')->insert([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'member_id' => $member->id, 'user_id' => $cashier->id,
            'type' => 'credit', 'payment_method' => 'cash', 'amount' => 3000, 'balance_after' => 3000,
            'description' => 'Top up deposit', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashier->id, 'category' => 'Lainnya',
            'description' => 'Beli es', 'amount' => 2000, 'payment_method' => 'cash', 'expense_date' => today(), 'report_type' => 'real',
        ]);

        $payload = ['opening_cash' => 5000, 'actual_cash' => 16000, 'approval_pin' => '2222'];
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->post('/kasir/tutup-harian', $payload)->assertStatus(422);
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->post('/kasir/tutup-harian', array_merge($payload, ['approval_pin' => '3333']))->assertRedirect();

        $this->assertDatabaseHas('cashier_closings', [
            'store_id' => $store->id, 'authorized_by' => $localSpv->id, 'opening_cash' => 5000,
            'cash_sales' => 10000, 'cash_topups' => 3000, 'cash_expenses' => 2000,
            'expected_cash' => 16000, 'actual_cash' => 16000, 'difference' => 0,
        ]);
    }

    public function test_setting_sub_permissions_are_enforced_per_action(): void
    {
        ['tenant' => $tenant, 'store' => $store] = $this->setupWarung();
        Role::create([
            'tenant_id' => $tenant->id, 'key' => 'brand_admin', 'name' => 'Brand Admin',
            'modules' => ['settings'], 'settings_permissions' => ['branding'], 'is_system' => false,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Brand', 'email' => 'brand@flow.test',
            'role' => 'brand_admin', 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($user)->get('/pengaturan')->assertOk()->assertSeeText('Identitas warung')->assertDontSeeText('Tambah cabang');
        $this->actingAs($user)->post('/pengaturan/brand', ['business_name' => 'Nama Baru'])->assertRedirect();
        $this->actingAs($user)->post('/pengaturan/cabang', ['name' => 'Terlarang', 'code' => 'NO'])->assertForbidden();
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'business_name' => 'Nama Baru']);
    }

    public function test_purchase_correction_reverses_old_stock_before_applying_new_values(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        $raw = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Minyak', 'product_type' => 'ingredient', 'sku' => 'MYK',
            'unit' => 'liter', 'purchase_price' => 10000, 'selling_price' => 0, 'minimum_stock' => 1,
        ]);
        $this->actingAs($admin)->withSession(['store_id' => $store->id])->post('/pembelian', [
            'supplier_name' => 'Supplier A', 'product_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 10000,
            'purchased_at' => today()->toDateString(), 'status' => 'received', 'payment_status' => 'paid',
        ])->assertRedirect();
        $purchase = Purchase::firstOrFail();

        $this->actingAs($admin)->withSession(['store_id' => $store->id])->put("/pembelian/{$purchase->id}", [
            'supplier_name' => 'Supplier Koreksi', 'product_id' => $raw->id, 'quantity' => 6, 'unit_cost' => 12000,
            'purchased_at' => today()->toDateString(), 'status' => 'received', 'payment_status' => 'paid',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_stocks', ['store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 6]);
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'supplier_name' => 'Supplier Koreksi', 'total' => 72000]);
    }

    public function test_archived_product_and_user_can_be_restored(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $cashier = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'restore@flow.test',
            'role' => User::CASHIER, 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($admin)->delete("/produk/{$menu->id}")->assertRedirect();
        $this->actingAs($admin)->post("/produk/{$menu->id}/restore")->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $menu->id, 'deleted_at' => null, 'is_active' => true]);

        $this->actingAs($admin)->patch("/pengaturan/pengguna/{$cashier->id}/status", ['is_active' => 0])->assertRedirect();
        $this->assertSoftDeleted($cashier);
        $this->actingAs($admin)->patch("/pengaturan/pengguna/{$cashier->id}/status", ['is_active' => 1])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'deleted_at' => null, 'is_active' => true]);
    }
}
