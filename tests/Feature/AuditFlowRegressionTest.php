<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConnectedDevice;
use App\Models\DailyMenuStock;
use App\Models\Member;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditFlowRegressionTest extends TestCase
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

    public function test_duplicate_menu_lines_cannot_overdraw_stock(): void
    {
        ['admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $this->actingAs($admin)->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 15], ['id' => $menu->id, 'qty' => 15]],
            'service_type' => 'takeaway', 'paid_amount' => 300000,
        ])->assertStatus(422);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 20]);
    }

    public function test_inactive_member_cannot_pay_or_hold_an_order(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create(['tenant_id' => $tenant->id, 'name' => 'Nonaktif', 'member_code' => 'OFF', 'qr_code' => 'OFF', 'is_active' => false, 'deposit_balance' => 50000]);
        foreach (['checkout', 'pending'] as $action) {
            $this->actingAs($admin)->postJson('/kasir/'.$action, [
                'items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway',
                'member_id' => $member->id, 'payment_method' => 'deposit',
            ])->assertNotFound();
        }
        $this->assertSame('50000.00', $member->fresh()->deposit_balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_local_device_manager_cannot_delete_foreign_or_shared_devices(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin] = $this->setupWarung();
        Role::create(['tenant_id' => $tenant->id, 'key' => 'devices_only', 'name' => 'Devices', 'modules' => ['settings'], 'settings_permissions' => ['devices']]);
        $admin->update(['role' => 'devices_only']);
        $other = Store::create(['tenant_id' => $tenant->id, 'name' => 'Rahasia Cabang', 'code' => 'OTHER']);
        foreach ([$other->id, null] as $storeId) {
            $device = ConnectedDevice::create(['tenant_id' => $tenant->id, 'store_id' => $storeId, 'name' => 'Printer', 'type' => 'receipt_printer', 'status' => 'active']);
            $this->actingAs($admin)->delete('/pengaturan/perangkat/'.$device->id)->assertNotFound();
            $this->assertDatabaseHas('connected_devices', ['id' => $device->id]);
        }
        $this->actingAs($admin)->get('/pengaturan')->assertOk()->assertDontSeeText('Rahasia Cabang');
    }

    public function test_custom_inventory_role_lands_on_an_allowed_page(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->setupWarung();
        Role::create(['tenant_id' => $tenant->id, 'key' => 'stock_only', 'name' => 'Stock', 'modules' => ['inventory']]);
        $admin->update(['role' => 'stock_only']);
        $this->actingAs($admin)->get('/')->assertRedirect(route('inventory'));
        $this->get('/login')->assertRedirect(route('inventory'));
    }

    public function test_receipt_survives_cashier_deactivation(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $cashier = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir Lama', 'email' => 'lama@test.test', 'role' => 'cashier', 'password' => 'password', 'is_active' => true]);
        $response = $this->actingAs($cashier)->postJson('/kasir/checkout', ['items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway', 'paid_amount' => 10000])->assertOk();
        $cashier->delete();
        $this->actingAs($admin)->get($response->json('print_url'))->assertOk()->assertSeeText('Kasir Lama');
        $this->get('/transaksi')->assertOk()->assertSeeText('Kasir Lama');
    }

    public function test_pos_only_role_can_scan_and_print_without_member_management(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        Role::create(['tenant_id' => $tenant->id, 'key' => 'pos_only', 'name' => 'POS', 'modules' => ['pos']]);
        $admin->update(['role' => 'pos_only']);
        Member::create(['tenant_id' => $tenant->id, 'name' => 'Aktif', 'member_code' => 'ON', 'qr_code' => 'ON', 'is_active' => true]);
        $this->actingAs($admin)->get('/member/find/ON')->assertOk();
        $this->get('/member')->assertForbidden();
        $response = $this->postJson('/kasir/checkout', ['items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway', 'paid_amount' => 10000])->assertOk();
        $this->get($response->json('print_url'))->assertOk();
        $this->get('/transaksi')->assertForbidden();
    }

    public function test_inactive_menu_is_rejected_without_stock_changes(): void
    {
        ['admin' => $admin, 'menu' => $menu] = $this->setupWarung();
        $menu->update(['is_active' => false]);
        $this->actingAs($admin)->postJson('/kasir/checkout', ['items' => [['id' => $menu->id, 'qty' => 1]], 'service_type' => 'takeaway', 'paid_amount' => 10000])->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('daily_menu_stocks', ['product_id' => $menu->id, 'quantity' => 20]);
    }
}
