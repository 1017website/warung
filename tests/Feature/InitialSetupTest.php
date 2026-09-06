<?php

namespace Tests\Feature;

use App\Models\{DailyMenuStock, Product, Role, Store, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name'=>'Pemilik', 'email'=>'owner@setup.test', 'password'=>'password', 'role'=>'superadmin', 'is_active'=>true]);
    }

    private function identity(): array
    {
        return ['step'=>1, 'business_name'=>'Usaha Uji', 'store_name'=>'Pusat', 'address'=>'Jl. Uji 12', 'phone'=>'08123456789', 'member_discount_percent'=>0, 'receipt_footer'=>'Terima kasih'];
    }

    private function product(): array
    {
        return ['step'=>2, 'category'=>'Makanan', 'product_name'=>'Nasi Goreng', 'unit'=>'porsi', 'selling_price'=>15000, 'quantity'=>12.5];
    }

    public function test_orphan_superadmin_can_login_and_cannot_skip_setup(): void
    {
        $user = $this->admin();
        $this->withSession(['url.intended'=>'/kasir'])->post('/login', ['email'=>$user->email, 'password'=>'password'])->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Identitas usaha');
        $this->get('/kasir')->assertRedirect('/setup');
        $this->postJson('/kasir/checkout', [])->assertStatus(409);
        $this->post('/setup', ['step'=>3, 'confirm'=>1])->assertRedirect('/setup');
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_complete_setup_is_resumable_and_retries_do_not_duplicate_data(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->post('/setup', $this->identity())->assertRedirect('/setup');
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('roles', 7);
        $this->post('/setup', $this->identity())->assertRedirect('/setup');
        $this->assertDatabaseCount('tenants', 1);
        $this->post('/logout');
        $this->post('/login', ['email'=>$user->email, 'password'=>'password'])->assertRedirect('/setup');
        $this->get('/setup')->assertSee('Tambahkan menu pertama');
        $this->post('/setup', $this->product())->assertRedirect('/setup');
        $this->post('/setup', $this->product())->assertRedirect('/setup');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('daily_menu_stocks', ['quantity'=>12.5]);
        $this->assertDatabaseHas('stock_movements', ['reference'=>'SETUP', 'quantity'=>12.5]);
        $this->post('/setup', ['step'=>3])->assertSessionHasErrors('confirm');
        $this->post('/setup', ['step'=>3, 'confirm'=>1])->assertRedirect('/setup');
        $this->get('/setup')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->get('/kasir')->assertOk()->assertSee('Nasi Goreng');
        $this->post('/setup', $this->product())->assertRedirect('/setup');
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('superadmin', $user->fresh()->role);
    }

    public function test_staff_can_only_see_pending_screen(): void
    {
        $user = $this->admin();
        $user->update(['role'=>'cashier']);
        $this->actingAs($user)->get('/setup')->assertOk()->assertSee('Superadmin perlu melengkapi')->assertDontSee('name="business_name"', false);
        $this->post('/setup', $this->identity())->assertForbidden();
        $this->get('/kasir')->assertRedirect('/setup');
    }

    public function test_invalid_data_is_not_partially_saved(): void
    {
        $this->actingAs($this->admin())->post('/setup', ['step'=>1])->assertSessionHasErrors('business_name');
        $this->assertDatabaseCount('tenants', 0);
        $this->post('/setup', $this->identity());
        $this->post('/setup', array_replace($this->product(), ['selling_price'=>-1, 'quantity'=>0]))->assertSessionHasErrors(['selling_price','quantity']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('tenants', ['setup_step'=>2]);
    }

    public function test_existing_configured_business_is_not_forced_through_setup(): void
    {
        $tenant = Tenant::create(['name'=>'Existing', 'slug'=>'existing']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id'=>$tenant->id, 'name'=>'Pusat', 'code'=>'PST']);
        $user = $this->admin();
        $user->update(['tenant_id'=>$tenant->id, 'store_id'=>$store->id]);
        $this->actingAs($user)->get('/setup')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_orphan_admin_gets_new_business_not_another_tenants_data(): void
    {
        $other = Tenant::create(['name'=>'Private', 'slug'=>'private']);
        $this->actingAs($user = $this->admin())->post('/setup', $this->identity());
        $this->assertNotEquals($other->id, $user->fresh()->tenant_id);
        $this->assertSame('Private', $other->fresh()->name);
        $this->assertDatabaseCount('tenants', 2);
    }
}
