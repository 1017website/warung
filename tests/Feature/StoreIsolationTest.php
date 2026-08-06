<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Satu tenant dengan dua warung: Melati (cabang user) dan Kenanga (warung lain). */
    private function setupTwoStores(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Dua Cabang', 'slug' => 'warung-dua-cabang']);
        Role::provisionDefaults($tenant->id);
        $melati = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Melati', 'code' => 'MLT', 'is_active' => true]);
        $kenanga = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Kenanga', 'code' => 'KNG', 'is_active' => true]);

        return compact('tenant', 'melati', 'kenanga');
    }

    private function makeUser(Tenant $tenant, Store $store, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => ucfirst($role),
            'email' => $role.'@isolasi.test', 'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function transactionFor(Tenant $tenant, Store $store, User $user, string $invoice): Transaction
    {
        return Transaction::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $user->id, 'invoice_no' => $invoice,
            'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real', 'subtotal' => 50000,
            'discount' => 0, 'total' => 50000, 'payment_method' => 'cash', 'paid_amount' => 50000,
            'change_amount' => 0, 'transacted_at' => now(),
        ]);
    }

    public static function limitedRoles(): array
    {
        return [['cashier'], ['spv'], ['outlet_manager'], ['ops_admin']];
    }

    #[DataProvider('limitedRoles')]
    public function test_limited_roles_cannot_switch_to_another_store(string $role): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $user = $this->makeUser($tenant, $melati, $role);

        $this->actingAs($user)->withSession(['store_id' => $melati->id])
            ->post('/switch-store', ['store_id' => $kenanga->id])->assertForbidden();
        $this->actingAs($user)->withSession(['store_id' => $melati->id])
            ->post('/switch-store', ['store_id' => 'consolidated'])->assertForbidden();
    }

    #[DataProvider('limitedRoles')]
    public function test_limited_roles_never_see_another_store_in_the_branch_switcher(string $role): void
    {
        ['tenant' => $tenant, 'melati' => $melati] = $this->setupTwoStores();
        $user = $this->makeUser($tenant, $melati, $role);

        $response = $this->actingAs($user)->get('/kasir')->assertOk();
        $response->assertDontSee('Cabang Kenanga');
        $response->assertDontSee('Consolidated');
        $response->assertViewHas('availableStores', fn ($stores) => $stores->count() === 1 && $stores->first()->id === $melati->id);
    }

    public function test_head_of_ops_and_superadmin_keep_full_store_access(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();

        foreach (['superadmin', 'head_ops'] as $role) {
            $user = $this->makeUser($tenant, $melati, $role);
            $this->actingAs($user)->withSession(['store_id' => $melati->id])
                ->post('/switch-store', ['store_id' => $kenanga->id])->assertRedirect();
            $this->assertSame($kenanga->id, session('store_id'));
            $this->actingAs($user)->post('/switch-store', ['store_id' => 'consolidated'])->assertRedirect();
            $this->actingAs($user)->get('/kasir')->assertOk()->assertSee('Cabang Kenanga');
        }
    }

    public function test_poisoned_session_cannot_leak_another_store_transactions(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $cashier = $this->makeUser($tenant, $melati, 'cashier');
        $this->transactionFor($tenant, $melati, $cashier, 'TRX-MELATI');
        $this->transactionFor($tenant, $kenanga, $cashier, 'TRX-KENANGA');

        // Session sengaja diarahkan ke warung lain; data tetap harus milik cabang sendiri.
        $response = $this->actingAs($cashier)
            ->withSession(['store_id' => $kenanga->id, 'view_scope' => 'consolidated'])
            ->get('/transaksi')->assertOk();

        $response->assertSee('TRX-MELATI')->assertDontSee('TRX-KENANGA');
        $response->assertViewHas('isConsolidated', false);
    }

    public function test_limited_role_cannot_print_receipt_from_another_store(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $cashier = $this->makeUser($tenant, $melati, 'cashier');
        $own = $this->transactionFor($tenant, $melati, $cashier, 'TRX-OWN');
        $foreign = $this->transactionFor($tenant, $kenanga, $cashier, 'TRX-FOREIGN');

        $this->actingAs($cashier)->get("/transaksi/{$own->id}/print")->assertOk();
        $this->actingAs($cashier)->get("/transaksi/{$foreign->id}/print")->assertNotFound();
    }

    public function test_limited_role_cannot_void_or_delete_records_of_another_store(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $cashier = $this->makeUser($tenant, $melati, 'cashier');
        $foreign = $this->transactionFor($tenant, $kenanga, $cashier, 'TRX-FOREIGN');
        $foreignExpense = Expense::create([
            'tenant_id' => $tenant->id, 'store_id' => $kenanga->id, 'user_id' => $cashier->id, 'category' => 'Listrik',
            'description' => 'Tagihan warung lain', 'amount' => 250000, 'expense_date' => today(), 'report_type' => 'real',
        ]);

        $this->actingAs($cashier)->delete("/transaksi/{$foreign->id}", ['reason' => 'coba batalkan'])->assertNotFound();
        $this->actingAs($cashier)->delete("/pengeluaran/{$foreignExpense->id}")->assertNotFound();
        $this->assertDatabaseHas('expenses', ['id' => $foreignExpense->id, 'deleted_at' => null]);
    }

    public function test_ops_admin_cannot_edit_purchase_of_another_store(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $opsAdmin = $this->makeUser($tenant, $melati, 'ops_admin');
        $ingredient = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Beras', 'product_type' => 'ingredient', 'sku' => 'BRS',
            'unit' => 'kg', 'purchase_price' => 12000, 'selling_price' => 0, 'minimum_stock' => 5,
        ]);
        $purchase = Purchase::create([
            'tenant_id' => $tenant->id, 'store_id' => $kenanga->id, 'user_id' => $opsAdmin->id, 'purchase_no' => 'PO-KNG',
            'supplier_name' => 'Toko Beras', 'total' => 120000, 'status' => 'draft', 'payment_status' => 'unpaid',
            'dp_amount' => 0, 'purchased_at' => today(),
        ]);
        $purchase->items()->create(['product_id' => $ingredient->id, 'product_name' => $ingredient->name, 'quantity' => 10, 'unit_cost' => 12000, 'subtotal' => 120000]);

        $this->actingAs($opsAdmin)->patch("/pembelian/{$purchase->id}/status", [
            'status' => 'received', 'payment_status' => 'paid',
        ])->assertNotFound();
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'status' => 'draft', 'payment_status' => 'unpaid']);
    }

    public function test_limited_role_stock_and_purchase_pages_only_show_own_store(): void
    {
        ['tenant' => $tenant, 'melati' => $melati, 'kenanga' => $kenanga] = $this->setupTwoStores();
        $spv = $this->makeUser($tenant, $melati, 'spv');
        Expense::create([
            'tenant_id' => $tenant->id, 'store_id' => $kenanga->id, 'user_id' => $spv->id, 'category' => 'Listrik',
            'description' => 'PENGELUARAN-KENANGA', 'amount' => 250000, 'expense_date' => today(), 'report_type' => 'real',
        ]);
        Expense::create([
            'tenant_id' => $tenant->id, 'store_id' => $melati->id, 'user_id' => $spv->id, 'category' => 'Listrik',
            'description' => 'PENGELUARAN-MELATI', 'amount' => 100000, 'expense_date' => today(), 'report_type' => 'real',
        ]);

        $this->actingAs($spv)->withSession(['store_id' => $kenanga->id])->get('/pengeluaran')
            ->assertOk()->assertSee('PENGELUARAN-MELATI')->assertDontSee('PENGELUARAN-KENANGA');
    }

    public function test_limited_roles_cannot_reach_revenue_pages_at_all(): void
    {
        ['tenant' => $tenant, 'melati' => $melati] = $this->setupTwoStores();

        foreach (['cashier', 'spv', 'outlet_manager', 'ops_admin'] as $role) {
            $user = $this->makeUser($tenant, $melati, $role);
            $this->actingAs($user)->get('/laporan')->assertForbidden();
            $this->actingAs($user)->get('/dashboard')->assertForbidden();
        }
    }
}
