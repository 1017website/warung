<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Member;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RevisionFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Revisi', 'slug' => 'warung-revisi', 'allow_custom_amount' => true]);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Supervisor', 'email' => 'spv@test.id',
            'role' => 'superadmin', 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('4321'),
        ]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Rica Bebek', 'product_type' => 'menu',
            'sku' => 'RB-1', 'unit' => 'porsi', 'purchase_price' => 5000, 'selling_price' => 10000,
            'online_selling_price' => 15000, 'minimum_stock' => 2,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 20]);

        return compact('tenant', 'store', 'user', 'menu');
    }

    public function test_online_member_checkout_supports_split_deposit_and_bank_detail(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-1', 'qr_code' => 'qr-1', 'name' => 'Rina', 'deposit_balance' => 20000, 'discount_percent' => 10]);

        $response = $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 2]], 'member_id' => $member->id,
            'discount_type' => 'member', 'discount_value' => 0, 'service_type' => 'online', 'online_platform' => 'GoFood',
            'payments' => [['method' => 'deposit', 'amount' => 20000], ['method' => 'transfer', 'provider' => 'BCA', 'amount' => 7000]],
        ]);

        $response->assertOk();
        $transaction = Transaction::firstOrFail();
        $this->assertEquals(30000, $transaction->subtotal);
        $this->assertEquals(3000, $transaction->discount);
        $this->assertEquals(27000, $transaction->total);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 0]);
        $this->assertDatabaseHas('transaction_payments', ['transaction_id' => $transaction->id, 'method' => 'transfer', 'provider' => 'BCA', 'amount' => 7000]);
    }

    public function test_pending_bill_does_not_reduce_stock_until_paid(): void
    {
        ['store' => $store, 'user' => $user, 'menu' => $menu] = $this->setupWarung();
        $payload = ['items' => [['id' => $menu->id, 'qty' => 3]], 'discount_type' => 'amount', 'discount_value' => 0, 'service_type' => 'dine_in', 'table_number' => 'A-1'];
        $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/pending', $payload)->assertOk();
        $pending = Transaction::where('status', 'pending')->firstOrFail();
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 20]);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', $payload + [
            'pending_transaction_id' => $pending->id, 'payments' => [['method' => 'cash', 'amount' => 30000]],
        ])->assertOk();
        $this->assertDatabaseHas('transactions', ['id' => $pending->id, 'status' => 'completed']);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 17]);
    }

    public function test_old_transaction_requires_supervisor_pin_and_restores_stock(): void
    {
        ['store' => $store, 'user' => $user, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway',
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertOk();
        $transaction = Transaction::firstOrFail();
        $transaction->update(['transacted_at' => now()->subMinute()]);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->delete("/transaksi/{$transaction->id}", ['reason' => 'Salah input pesanan'])->assertStatus(422);
        $this->actingAs($user)->withSession(['store_id' => $store->id])->delete("/transaksi/{$transaction->id}", ['reason' => 'Salah input pesanan', 'approval_pin' => '4321'])->assertRedirect();
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'voided', 'cancel_reason' => 'Salah input pesanan']);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 20]);
    }

    public function test_production_connects_raw_material_to_processed_stock_and_opname(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user, 'menu' => $menu] = $this->setupWarung();
        $raw = Product::create(['tenant_id' => $tenant->id, 'name' => 'Bebek', 'product_type' => 'ingredient', 'sku' => 'BB-1', 'unit' => 'ekor', 'purchase_price' => 30000, 'selling_price' => 0, 'minimum_stock' => 3]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 10]);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/gudang/production', [
            'ingredient_product_id' => $raw->id, 'ingredient_quantity' => 2, 'menu_product_id' => $menu->id, 'output_quantity' => 5,
        ])->assertRedirect();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $raw->id, 'quantity' => 8]);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 25]);
        $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/gudang/count', ['product_id' => $menu->id, 'actual_quantity' => 24, 'notes' => 'Selisih satu'])->assertRedirect();
        $this->assertDatabaseHas('stock_counts', ['product_id' => $menu->id, 'expected_quantity' => 25, 'actual_quantity' => 24]);
    }

    public function test_purchase_receipt_and_payment_status_are_live_editable(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $raw = Product::create(['tenant_id' => $tenant->id, 'name' => 'Minyak', 'product_type' => 'ingredient', 'sku' => 'MYK', 'unit' => 'liter', 'purchase_price' => 15000, 'selling_price' => 0, 'minimum_stock' => 2]);
        $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/pembelian', [
            'supplier_name' => 'Supplier A', 'product_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 16000,
            'purchased_at' => today()->toDateString(), 'status' => 'not_received', 'payment_status' => 'unpaid',
        ])->assertRedirect();
        $purchase = Purchase::firstOrFail();
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $raw->id, 'quantity' => 10]);
        $this->actingAs($user)->withSession(['store_id' => $store->id])->patch("/pembelian/{$purchase->id}/status", [
            'status' => 'received', 'payment_status' => 'dp', 'dp_amount' => 50000,
        ])->assertRedirect();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $raw->id, 'quantity' => 10]);
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'status' => 'received', 'payment_status' => 'dp', 'dp_amount' => 50000]);
    }

    public function test_consolidated_report_compares_all_stores(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $second = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Dua', 'code' => 'CB2', 'is_active' => true]);
        foreach ([[$store, 'TRX-A', 10000], [$second, 'TRX-B', 20000]] as [$target, $invoice, $total]) {
            Transaction::create(['tenant_id' => $tenant->id, 'store_id' => $target->id, 'user_id' => $user->id, 'invoice_no' => $invoice, 'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real', 'subtotal' => $total, 'discount' => 0, 'total' => $total, 'payment_method' => 'cash', 'paid_amount' => $total, 'change_amount' => 0, 'transacted_at' => now()]);
        }

        $this->actingAs($user)->withSession(['store_id' => $store->id, 'view_scope' => 'consolidated'])->get('/laporan?period=today')
            ->assertOk()->assertViewHas('sales', 30000.0)->assertViewHas('storeComparison', fn ($rows) => $rows->count() === 2)->assertSeeText('Perbandingan seluruh warung');
    }

    public function test_business_rules_and_card_first_membership_registration(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $card = MemberCard::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-P00001', 'qr_code' => 'WK-MBR-P00001', 'status' => 'available']);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/pengaturan/aturan-bisnis', [
            'non_real_percentage' => 65,
            'member_discount_percent' => 12.5,
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->withSession(['store_id' => $store->id])->getJson('/member/card/WK-MBR-P00001')
            ->assertOk()->assertJson(['id' => $card->id, 'member_code' => 'MBR-P00001']);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/member', [
            'member_card_id' => $card->id,
            'name' => 'Siti Rahma',
            'phone' => '081200000001',
            'domicile' => 'Jakarta',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'non_real_percentage' => 65, 'member_discount_percent' => 12.5]);
        $this->assertDatabaseHas('members', ['member_code' => 'MBR-P00001', 'name' => 'Siti Rahma', 'discount_percent' => 12.5]);
        $this->assertDatabaseHas('member_cards', ['id' => $card->id, 'status' => 'assigned']);
        $this->actingAs($user)->getJson('/member/card/WK-MBR-P00001')->assertNotFound();
    }

    public function test_buffet_item_can_be_sold_by_gram(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Lauk Prasmanan']);
        $shrimp = Product::create([
            'tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Udang Goreng', 'product_type' => 'menu',
            'sku' => 'UDG-G', 'unit' => 'gram', 'purchase_price' => 120, 'selling_price' => 250,
            'online_selling_price' => 280, 'minimum_stock' => 50,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $shrimp->id, 'stock_date' => today(), 'quantity' => 500]);

        $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', [
            'items' => [['id' => $shrimp->id, 'qty' => 150]],
            'service_type' => 'takeaway',
            'payments' => [['method' => 'cash', 'amount' => 37500]],
        ])->assertOk();

        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $shrimp->id, 'quantity' => 350]);
        $this->assertDatabaseHas('transaction_items', ['product_id' => $shrimp->id, 'quantity' => 150, 'subtotal' => 37500]);
    }
}
