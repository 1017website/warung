<?php

namespace Tests\Feature;

use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptionIssueRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Caption Test', 'slug' => 'caption-test']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pusat',
            'code' => 'PST',
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'name' => 'Admin',
            'email' => 'caption@test.test',
            'role' => 'superadmin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($user)->withSession(['store_id' => $store->id]);

        return compact('tenant', 'store', 'user');
    }

    public function test_manually_created_menu_is_available_for_purchase_and_updates_menu_stock(): void
    {
        ['tenant' => $tenant, 'store' => $store] = $this->fixture();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Produk Manual',
            'product_type' => 'menu',
            'sku' => 'MANUAL-1',
            'unit' => 'pcs',
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'minimum_stock' => 1,
            'is_active' => true,
        ]);
        DailyMenuStock::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'stock_date' => today(),
            'quantity' => 2,
        ]);

        $this->get('/pembelian')->assertOk()->assertSeeText('Produk Manual');
        $this->post('/pembelian', [
            'supplier_name' => 'Supplier',
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 5000,
            'purchased_at' => today()->toDateString(),
            'status' => 'received',
            'payment_status' => 'paid',
        ])->assertRedirect();

        $stock = DailyMenuStock::where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereDate('stock_date', today())
            ->firstOrFail();
        $this->assertSame(5.0, (float) $stock->quantity);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $product->id]);
    }

    public function test_expense_can_be_edited(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'user' => $user] = $this->fixture();
        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'category' => 'Others',
            'description' => 'Salah catat',
            'amount' => 10000,
            'payment_method' => 'cash',
            'report_type' => 'real',
            'expense_date' => today(),
        ]);

        $this->put('/pengeluaran/'.$expense->id, [
            'category' => 'Marketing',
            'description' => 'Iklan lokal',
            'amount' => 25000,
            'payment_method' => 'transfer',
            'expense_date' => today()->subDay()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'category' => 'Marketing',
            'description' => 'Iklan lokal',
            'amount' => 25000,
            'payment_method' => 'transfer',
        ]);
    }

    public function test_repeated_product_delete_is_idempotent(): void
    {
        ['tenant' => $tenant] = $this->fixture();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Produk Acak',
            'product_type' => 'menu',
            'sku' => 'RANDOM-1',
            'unit' => 'pcs',
            'selling_price' => 1000,
            'is_active' => true,
        ]);

        $this->delete('/produk/'.$product->id)->assertRedirect();
        $this->delete('/produk/'.$product->id)->assertRedirect();
        $this->assertSoftDeleted($product);
        $this->assertFalse(Product::withTrashed()->findOrFail($product->id)->is_active);
    }

    public function test_web_business_error_returns_to_cashier_with_message_instead_of_raw_422_page(): void
    {
        ['tenant' => $tenant, 'store' => $store] = $this->fixture();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Menu',
            'product_type' => 'menu',
            'sku' => 'MENU-1',
            'unit' => 'pcs',
            'selling_price' => 10000,
            'is_active' => true,
        ]);
        DailyMenuStock::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'stock_date' => today(),
            'quantity' => 2,
        ]);

        $this->from('/kasir')->post('/kasir/checkout', [
            'items' => [['id' => $product->id, 'qty' => 1]],
            'service_type' => 'takeaway',
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertRedirect('/kasir')->assertSessionHasErrors();

        $this->assertDatabaseCount('transactions', 0);
    }
}
