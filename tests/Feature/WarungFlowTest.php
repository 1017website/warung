<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WarungFlowTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(string $role = 'admin'): array
    {
        $tenant = Tenant::create(['name' => 'Test Warung', 'slug' => 'test-warung']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $user = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Admin', 'email' => $role.'@test.id', 'role' => $role, 'is_active' => true, 'password' => 'password']);

        return compact('tenant', 'store', 'user');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_roles_are_enforced(): void
    {
        ['user' => $cashier] = $this->setupWarung('cashier');
        $this->actingAs($cashier)->get('/kasir')->assertOk();
        $this->actingAs($cashier)->get('/pengaturan')->assertForbidden();
    }

    public function test_checkout_reduces_stock_and_deposit_balance(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Minuman']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Kopi', 'product_type' => 'menu', 'sku' => 'KOPI', 'unit' => 'cup', 'purchase_price' => 4000, 'selling_price' => 10000, 'minimum_stock' => 2]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id, 'stock_date' => today(), 'quantity' => 10]);
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'M-1', 'qr_code' => 'qr-test', 'name' => 'Rina', 'deposit_balance' => 50000]);

        $response = $this->actingAs($user)->withSession(['store_id' => $store->id])->postJson('/kasir/checkout', [
            'items' => [['id' => $product->id, 'qty' => 2]], 'member_id' => $member->id,
            'discount' => 0, 'payment_method' => 'deposit', 'paid_amount' => 0, 'report_type' => 'non_real',
            'service_type' => 'dine_in', 'table_number' => 'A-7',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $product->id, 'stock_date' => today()->toDateString(), 'quantity' => 8]);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 30000]);
        $this->assertDatabaseHas('transactions', ['member_id' => $member->id, 'total' => 20000, 'payment_method' => 'deposit', 'report_type' => 'real', 'service_type' => 'dine_in', 'table_number' => 'A-7']);
    }

    public function test_product_delete_is_soft_delete(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Teh', 'sku' => 'TEH', 'unit' => 'pcs', 'purchase_price' => 1000, 'selling_price' => 3000, 'minimum_stock' => 2]);
        $this->actingAs($user)->withSession(['store_id' => $store->id])->delete("/produk/{$product->id}")->assertRedirect();
        $this->assertSoftDeleted($product);
    }

    public function test_admin_can_render_every_main_module(): void
    {
        $this->seed();
        $admin = User::where('role', 'admin')->firstOrFail();

        foreach (['/dashboard', '/kasir', '/transaksi', '/produk', '/gudang', '/pembelian', '/pengeluaran', '/member', '/laporan', '/pengaturan'] as $url) {
            $this->actingAs($admin)->withSession(['store_id' => $admin->store_id])->get($url)->assertOk();
        }

        $transaction = Transaction::firstOrFail();
        $this->actingAs($admin)->get("/transaksi/{$transaction->id}/print")->assertOk();
    }

    public function test_employee_cannot_discover_or_request_non_real_report(): void
    {
        ['store' => $store, 'user' => $admin] = $this->setupWarung('admin');

        $this->actingAs($admin)
            ->withSession(['store_id' => $store->id])
            ->get('/laporan?type=non_real')
            ->assertOk()
            ->assertViewHas('type', 'real')
            ->assertViewHas('canSeeNonReal', false)
            ->assertDontSee('Non-riil');
    }

    public function test_owner_non_real_report_is_automatically_half_of_real_figures(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $owner] = $this->setupWarung('owner');
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Nasi Goreng', 'sku' => 'NG-1', 'unit' => 'porsi', 'purchase_price' => 4000, 'selling_price' => 10000, 'minimum_stock' => 2]);
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $owner->id,
            'invoice_no' => 'TRX-REPORT-1', 'report_type' => 'real', 'subtotal' => 10000,
            'discount' => 0, 'total' => 10000, 'payment_method' => 'cash',
            'paid_amount' => 10000, 'change_amount' => 0, 'transacted_at' => now(),
        ]);
        $transaction->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'price' => 10000, 'cost' => 4000, 'subtotal' => 10000]);
        Expense::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $owner->id, 'category' => 'Operasional', 'description' => 'Gas', 'amount' => 1000, 'report_type' => 'real', 'expense_date' => today()]);

        $report = $this->actingAs($owner)
            ->withSession(['store_id' => $store->id])
            ->get('/laporan?type=non_real');
        $report->assertOk()
            ->assertViewHas('canSeeNonReal', true)
            ->assertViewHas('sales', 5000.0)
            ->assertViewHas('cost', 2000.0)
            ->assertViewHas('expenses', 500.0)
            ->assertViewHas('profit', 2500.0)
            ->assertSeeText('Detail transaksi')
            ->assertSeeText('TRX-REPORT-1')
            ->assertSeeText('Nasi Goreng')
            ->assertSeeText('Rp 3.000');

        $export = $this->actingAs($owner)
            ->withSession(['store_id' => $store->id])
            ->get('/laporan/export?type=non_real');
        $export->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $content = $export->streamedContent();
        $this->assertStringStartsWith('PK', $content);

        $samplePath = storage_path('app/testing/non-real-report.xlsx');
        File::ensureDirectoryExists(dirname($samplePath));
        File::put($samplePath, $content);
        $workbook = IOFactory::load($samplePath);

        $this->assertSame(['Ringkasan', 'Transaksi', 'Pengeluaran'], $workbook->getSheetNames());
        $this->assertEquals(5000, $workbook->getSheetByName('Transaksi')->getCell('K5')->getValue());
        $this->assertEquals(2000, $workbook->getSheetByName('Transaksi')->getCell('L5')->getValue());
        $this->assertEquals(500, $workbook->getSheetByName('Pengeluaran')->getCell('E5')->getValue());
        $this->assertEquals(2500, $workbook->getSheetByName('Ringkasan')->getCell('B10')->getCalculatedValue());
        $workbook->disconnectWorksheets();
    }

    public function test_employee_export_request_is_forced_to_real_workbook(): void
    {
        ['store' => $store, 'user' => $admin] = $this->setupWarung('admin');
        $response = $this->actingAs($admin)
            ->withSession(['store_id' => $store->id])
            ->get('/laporan/export?type=non_real');

        $response->assertOk();
        $this->assertStringContainsString('laporan-transaksi-real-', $response->headers->get('content-disposition'));
        $path = storage_path('app/testing/employee-report.xlsx');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $response->streamedContent());
        $workbook = IOFactory::load($path);
        $this->assertStringContainsString('RIIL', $workbook->getSheetByName('Transaksi')->getCell('A1')->getValue());
        $this->assertStringNotContainsString('NON-RIIL', $workbook->getSheetByName('Transaksi')->getCell('A1')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_checkout_validates_restaurant_service_details(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Ayam Geprek', 'product_type' => 'menu', 'sku' => 'AG-1', 'unit' => 'porsi', 'purchase_price' => 8000, 'selling_price' => 18000, 'minimum_stock' => 2]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id, 'stock_date' => today(), 'quantity' => 5]);
        $base = ['items' => [['id' => $product->id, 'qty' => 1]], 'discount' => 0, 'payment_method' => 'cash', 'paid_amount' => 20000];

        $this->actingAs($user)->withSession(['store_id' => $store->id])
            ->postJson('/kasir/checkout', $base + ['service_type' => 'dine_in'])
            ->assertUnprocessable()->assertJsonValidationErrors('table_number');

        $this->actingAs($user)->withSession(['store_id' => $store->id])
            ->postJson('/kasir/checkout', $base + ['service_type' => 'online'])
            ->assertUnprocessable()->assertJsonValidationErrors('online_platform');

        $this->actingAs($user)->withSession(['store_id' => $store->id])
            ->postJson('/kasir/checkout', $base + ['service_type' => 'online', 'online_platform' => 'GoFood'])
            ->assertOk();
        $this->assertDatabaseHas('transactions', ['service_type' => 'online', 'online_platform' => 'GoFood', 'table_number' => null]);
    }

    public function test_topup_button_endpoint_adds_balance_and_audit_record(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'M-2', 'qr_code' => 'qr-topup', 'name' => 'Dewi', 'deposit_balance' => 10000]);

        $this->actingAs($user)->withSession(['store_id' => $store->id])
            ->post("/member/{$member->id}/topup", ['amount' => 50000])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 60000]);
        $this->assertDatabaseHas('deposit_transactions', ['member_id' => $member->id, 'type' => 'credit', 'amount' => 50000, 'balance_after' => 60000]);
    }

    public function test_inventory_adjustment_uses_separate_stock_tables(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->setupWarung();
        $ingredient = Product::create(['tenant_id' => $tenant->id, 'name' => 'Beras', 'product_type' => 'ingredient', 'sku' => 'BRS', 'unit' => 'kg', 'purchase_price' => 15000, 'selling_price' => 0, 'minimum_stock' => 5]);
        $menu = Product::create(['tenant_id' => $tenant->id, 'name' => 'Nasi Ayam', 'product_type' => 'menu', 'sku' => 'NAY', 'unit' => 'porsi', 'purchase_price' => 9000, 'selling_price' => 20000, 'minimum_stock' => 3]);

        foreach ([[$ingredient, 20], [$menu, 12]] as [$product, $quantity]) {
            $this->actingAs($user)->withSession(['store_id' => $store->id])->post('/gudang/adjust', [
                'product_id' => $product->id, 'type' => 'adjustment_in', 'quantity' => $quantity, 'notes' => 'Tes stok',
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('product_stocks', ['product_id' => $ingredient->id, 'quantity' => 20]);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'stock_date' => today()->toDateString(), 'quantity' => 12]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $menu->id]);
    }

    public function test_report_has_simple_quick_period_filters(): void
    {
        ['store' => $store, 'user' => $admin] = $this->setupWarung('admin');

        $this->actingAs($admin)->withSession(['store_id' => $store->id])
            ->get('/laporan?period=today')
            ->assertOk()
            ->assertViewHas('period', 'today')
            ->assertViewHas('from', fn ($date) => $date->isToday())
            ->assertViewHas('to', fn ($date) => $date->isToday())
            ->assertSeeText('Hari ini')
            ->assertSeeText('Minggu ini')
            ->assertSeeText('Bulan ini')
            ->assertSeeText('Tahun ini');
    }

    public function test_only_administrator_roles_can_run_whitelisted_maintenance_commands(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $admin] = $this->setupWarung('admin');
        $cashier = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'cashier-maintenance@test.id', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);

        Artisan::shouldReceive('call')->once()->with('optimize:clear', [])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Cache berhasil dibersihkan.');

        $this->actingAs($admin)->withSession(['store_id' => $store->id])
            ->post('/pengaturan/pemeliharaan', ['command' => 'optimize_clear'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('maintenance_output', 'Cache berhasil dibersihkan.');

        $this->actingAs($admin)->withSession(['store_id' => $store->id])
            ->post('/pengaturan/pemeliharaan', ['command' => 'command_bebas'])
            ->assertSessionHasErrors('command');

        $this->actingAs($cashier)->withSession(['store_id' => $store->id])
            ->post('/pengaturan/pemeliharaan', ['command' => 'optimize_clear'])
            ->assertForbidden();
    }
}
