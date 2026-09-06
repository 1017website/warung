<?php

namespace Tests\Feature;

use App\Models\{DailyMenuStock, Product, ProductStock, Purchase, Role, Store, Tenant, Transaction, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeptemberAuditFixTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Audit', 'slug' => 'audit-fixes']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $user = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Admin', 'email' => 'audit@test.test', 'role' => 'superadmin', 'password' => 'password', 'is_active' => true]);
        $menu = Product::create(['tenant_id' => $tenant->id, 'name' => 'Menu kemarin', 'sku' => 'DAY', 'product_type' => 'menu', 'unit' => 'pcs', 'purchase_price' => 2000, 'selling_price' => 25000, 'minimum_stock' => 10, 'is_active' => true]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today()->subDay(), 'quantity' => 7]);
        $this->actingAs($user)->withSession(['store_id' => $store->id]);
        return compact('tenant', 'store', 'user', 'menu');
    }

    public static function stockPages(): array
    {
        return [['/kasir'], ['/produk'], ['/dashboard'], ['/gudang']];
    }

    #[DataProvider('stockPages')]
    public function test_first_stock_page_carries_yesterday_without_visiting_inventory(string $url): void
    {
        $f = $this->fixture();
        $other = Store::create(['tenant_id' => $f['tenant']->id, 'name' => 'Lain', 'code' => 'OTH', 'is_active' => true]);
        DailyMenuStock::create(['tenant_id' => $f['tenant']->id, 'store_id' => $other->id, 'product_id' => $f['menu']->id, 'stock_date' => today()->subDay(), 'quantity' => 99]);
        $this->get($url)->assertOk();
        $attributes = ['store_id' => $f['store']->id, 'product_id' => $f['menu']->id, 'stock_date' => today()->toDateString()];
        $this->assertDatabaseHas('daily_menu_stocks', $attributes + ['quantity' => 7]);
        $this->assertDatabaseMissing('daily_menu_stocks', ['store_id' => $other->id, 'stock_date' => today()->toDateString()]);
        DailyMenuStock::where($attributes)->update(['quantity' => 0]);
        $this->get($url)->assertOk();
        $this->assertDatabaseHas('daily_menu_stocks', $attributes + ['quantity' => 0]);
    }

    public function test_consolidated_dashboard_prepares_each_store_and_new_store_starts_at_zero(): void
    {
        $f = $this->fixture();
        $new = Store::create(['tenant_id' => $f['tenant']->id, 'name' => 'Baru', 'code' => 'NEW', 'is_active' => true]);
        $this->withSession(['view_scope' => 'consolidated'])->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $new->id, 'product_id' => $f['menu']->id, 'stock_date' => today()->toDateString(), 'quantity' => 0]);
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $f['store']->id, 'product_id' => $f['menu']->id, 'stock_date' => today()->toDateString(), 'quantity' => 7]);
    }

    public function test_underpayment_never_changes_stock_or_saves_transaction(): void
    {
        $f = $this->fixture();
        foreach ([0, 1000, 24999] as $amount) {
            $this->postJson('/kasir/checkout', ['items' => [['id' => $f['menu']->id, 'qty' => 1]], 'service_type' => 'takeaway', 'payments' => [['method' => 'cash', 'amount' => $amount]]])->assertStatus(422);
        }
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->get('/kasir')->assertOk();
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $f['menu']->id, 'stock_date' => today()->toDateString(), 'quantity' => 7]);
    }

    private function purchaseFixture(): array
    {
        $f = $this->fixture();
        $raw = Product::create(['tenant_id' => $f['tenant']->id, 'name' => 'Bahan', 'sku' => 'RAW', 'product_type' => 'ingredient', 'unit' => 'pcs', 'purchase_price' => 1000, 'selling_price' => 0]);
        $data = ['supplier_name' => 'Supplier', 'product_id' => $raw->id, 'quantity' => 10, 'unit_cost' => 1000, 'purchased_at' => today()->toDateString(), 'status' => 'received', 'payment_status' => 'unpaid', 'dp_amount' => 0];
        $this->post('/pembelian', $data)->assertRedirect();
        $this->post('/gudang/adjust', ['product_id' => $raw->id, 'type' => 'adjustment_out', 'quantity' => 8, 'notes' => 'Bahan terpakai'])->assertRedirect();
        return $f + ['raw' => $raw, 'purchase' => Purchase::firstOrFail(), 'data' => $data];
    }

    public function test_purchase_metadata_and_price_can_change_after_consumption_without_stock_movement(): void
    {
        $f = $this->purchaseFixture();
        $count = DB::table('stock_movements')->count();
        $this->put('/pembelian/'.$f['purchase']->id, array_merge($f['data'], ['supplier_name' => 'Koreksi', 'notes' => 'Catatan baru', 'unit_cost' => 1200]))->assertRedirect();
        $this->assertDatabaseHas('purchases', ['id' => $f['purchase']->id, 'supplier_name' => 'Koreksi', 'total' => 12000]);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 2]);
        $this->assertSame($count, DB::table('stock_movements')->count());
    }

    public function test_purchase_quantity_uses_net_delta_and_rolls_back_invalid_reversal(): void
    {
        $f = $this->purchaseFixture();
        $url = '/pembelian/'.$f['purchase']->id;
        $this->put($url, array_merge($f['data'], ['quantity' => 9]))->assertRedirect();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 1]);
        $this->put($url, array_merge($f['data'], ['quantity' => 12]))->assertRedirect();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 4]);
        $moves = DB::table('stock_movements')->count();
        $this->putJson($url, array_merge($f['data'], ['quantity' => 7]))->assertStatus(422);
        $this->putJson($url, array_merge($f['data'], ['status' => 'not_received']))->assertStatus(422);
        $this->assertDatabaseHas('purchases', ['id' => $f['purchase']->id, 'total' => 12000, 'status' => 'received']);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 4]);
        $this->assertSame($moves, DB::table('stock_movements')->count());
    }

    public function test_changing_purchase_product_cannot_remove_consumed_stock(): void
    {
        $f = $this->purchaseFixture();
        $other = Product::create(['tenant_id' => $f['tenant']->id, 'name' => 'Bahan lain', 'sku' => 'RAW2', 'product_type' => 'ingredient', 'unit' => 'pcs', 'selling_price' => 0]);
        $this->putJson('/pembelian/'.$f['purchase']->id, array_merge($f['data'], ['product_id' => $other->id]))->assertStatus(422);
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $f['purchase']->id, 'product_id' => $f['raw']->id]);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 2]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $other->id]);
    }

    public function test_dp_status_preserves_integer_rupiah_and_does_not_receive_stock_twice(): void
    {
        $f = $this->purchaseFixture();
        foreach ([1000, 5000, 10000] as $amount) {
            $this->patch('/pembelian/'.$f['purchase']->id.'/status', ['status' => 'received', 'payment_status' => 'dp', 'dp_amount' => $amount])->assertRedirect();
            $this->assertDatabaseHas('purchases', ['id' => $f['purchase']->id, 'dp_amount' => $amount]);
            $this->assertDatabaseHas('product_stocks', ['product_id' => $f['raw']->id, 'quantity' => 2]);
        }
    }

    public function test_single_module_roles_have_no_forbidden_cross_module_links(): void
    {
        $f = $this->fixture();
        foreach (['transactions' => ['/transaksi', ['Transaksi baru']], 'dashboard' => ['/dashboard', ['Buka kasir', 'Lihat semua', 'Semua transaksi']]] as $module => [$url, $labels]) {
            Role::create(['tenant_id' => $f['tenant']->id, 'key' => $module.'_only', 'name' => $module, 'modules' => [$module]]);
            $user = User::create(['tenant_id' => $f['tenant']->id, 'store_id' => $f['store']->id, 'name' => $module, 'email' => $module.'@audit.test', 'role' => $module.'_only', 'password' => 'password', 'is_active' => true]);
            $response = $this->actingAs($user)->get($url)->assertOk();
            foreach ($labels as $label) { $response->assertDontSeeText($label); }
            $this->get('/kasir')->assertForbidden();
        }
    }

    public function test_report_type_tabs_keep_custom_dates_in_both_directions(): void
    {
        $this->fixture();
        foreach (['real' => 'non_real', 'non_real' => 'real'] as $type => $target) {
            $response = $this->get('/laporan?type='.$type.'&period=custom&from=2026-08-01&to=2026-08-15')->assertOk();
            $response->assertSee(e(route('reports', ['type' => $target, 'period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15'])), false);
        }
    }
}
