<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMasterTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Master', 'slug' => 'warung-master']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $superadmin = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Bos', 'email' => 'bos@master.test',
            'role' => User::SUPERADMIN, 'is_active' => true, 'password' => 'password',
        ]);

        return compact('tenant', 'store', 'superadmin');
    }

    private function roleKey(int $tenantId, string $key): Role
    {
        return Role::where('tenant_id', $tenantId)->where('key', $key)->firstOrFail();
    }

    public function test_migration_default_data_matches_the_revision_document(): void
    {
        ['tenant' => $tenant] = $this->setupWarung();
        $roles = Role::where('tenant_id', $tenant->id)->orderBy('position')->get();

        $this->assertSame(
            ['superadmin', 'head_ops', 'ops_admin', 'outlet_manager', 'spv', 'cashier'],
            $roles->pluck('key')->all()
        );
        $this->assertSame(
            ['dashboard', 'pos', 'transactions', 'products', 'inventory', 'purchases', 'expenses', 'members', 'reports'],
            $this->roleKey($tenant->id, 'head_ops')->modules
        );
        $this->assertSame(
            ['pos', 'transactions', 'products', 'inventory', 'purchases', 'expenses', 'members'],
            $this->roleKey($tenant->id, 'ops_admin')->modules
        );
        $this->assertSame(
            ['pos', 'transactions', 'inventory', 'expenses', 'members'],
            $this->roleKey($tenant->id, 'cashier')->modules
        );
        // Hanya Superadmin & Head of Ops yang boleh melihat warung lain.
        $this->assertSame(['superadmin', 'head_ops'], $roles->where('can_access_all_stores', true)->pluck('key')->all());
        $this->assertSame(['superadmin'], $roles->where('can_see_non_real', true)->pluck('key')->all());
        $this->assertSame(['superadmin', 'head_ops', 'outlet_manager', 'spv'], $roles->where('is_supervisor', true)->pluck('key')->values()->all());
    }

    public function test_provision_defaults_is_idempotent(): void
    {
        ['tenant' => $tenant] = $this->setupWarung();
        Role::provisionDefaults($tenant->id);
        Role::provisionDefaults($tenant->id);

        $this->assertSame(6, Role::where('tenant_id', $tenant->id)->count());
    }

    public function test_superadmin_grants_a_menu_and_the_role_can_open_it_immediately(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'superadmin' => $superadmin] = $this->setupWarung();
        $cashierRole = $this->roleKey($tenant->id, 'cashier');
        $cashier = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'kasir@master.test',
            'role' => 'cashier', 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($cashier)->get('/produk')->assertForbidden();

        $this->actingAs($superadmin)->put("/pengaturan/role/{$cashierRole->id}", [
            'name' => 'Kasir', 'summary' => 'Kasir + produk',
            'modules' => ['pos', 'transactions', 'inventory', 'expenses', 'members', 'products'],
        ])->assertRedirect()->assertSessionHas('success');

        // fresh() meniru permintaan berikutnya, yang selalu memuat ulang user dari session.
        $this->actingAs($cashier->fresh())->get('/produk')->assertOk();
        $this->assertContains('products', $this->roleKey($tenant->id, 'cashier')->modules);
    }

    public function test_revoking_a_menu_closes_the_page_and_hides_it_from_the_sidebar(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'superadmin' => $superadmin] = $this->setupWarung();
        $opsRole = $this->roleKey($tenant->id, 'ops_admin');
        $opsAdmin = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Ops', 'email' => 'ops@master.test',
            'role' => 'ops_admin', 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($opsAdmin)->get('/pembelian')->assertOk();

        $this->actingAs($superadmin)->put("/pengaturan/role/{$opsRole->id}", [
            'name' => 'Ops Admin', 'modules' => ['pos', 'transactions', 'inventory', 'expenses', 'members'],
        ])->assertRedirect();

        $this->actingAs($opsAdmin->fresh())->get('/pembelian')->assertForbidden();
        $this->actingAs($opsAdmin->fresh())->get('/kasir')->assertOk()->assertDontSee('href="http://localhost/pembelian"', false);
    }

    public function test_system_role_cannot_be_edited_or_deleted(): void
    {
        ['tenant' => $tenant, 'superadmin' => $superadmin] = $this->setupWarung();
        $systemRole = $this->roleKey($tenant->id, User::SUPERADMIN);

        $this->actingAs($superadmin)->put("/pengaturan/role/{$systemRole->id}", [
            'name' => 'Superadmin', 'modules' => ['pos'],
        ])->assertStatus(422);
        $this->actingAs($superadmin)->delete("/pengaturan/role/{$systemRole->id}")->assertStatus(422);

        $this->assertCount(10, $systemRole->fresh()->modules);
    }

    public function test_role_still_used_by_an_account_cannot_be_deleted(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'superadmin' => $superadmin] = $this->setupWarung();
        $spvRole = $this->roleKey($tenant->id, 'spv');
        User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Spv', 'email' => 'spv@master.test',
            'role' => 'spv', 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($superadmin)->delete("/pengaturan/role/{$spvRole->id}")->assertStatus(422);
        $this->assertDatabaseHas('roles', ['id' => $spvRole->id, 'deleted_at' => null]);
    }

    public function test_unused_role_can_be_deleted(): void
    {
        ['tenant' => $tenant, 'superadmin' => $superadmin] = $this->setupWarung();
        $spvRole = $this->roleKey($tenant->id, 'spv');

        $this->actingAs($superadmin)->delete("/pengaturan/role/{$spvRole->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertSoftDeleted($spvRole);
    }

    public function test_superadmin_creates_a_new_role_and_assigns_it_to_an_account(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'superadmin' => $superadmin] = $this->setupWarung();

        $this->actingAs($superadmin)->post('/pengaturan/role', [
            'key' => 'kasir_senior', 'name' => 'Kasir Senior', 'summary' => 'Kasir + laporan',
            'modules' => ['pos', 'transactions', 'members', 'reports'],
            'is_supervisor' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $newRole = $this->roleKey($tenant->id, 'kasir_senior');
        $this->assertSame(['pos', 'transactions', 'members', 'reports'], $newRole->modules);
        $this->assertTrue($newRole->is_supervisor);
        // Role baru selalu terkunci ke cabangnya sendiri.
        $this->assertFalse($newRole->can_access_all_stores);

        $this->actingAs($superadmin)->post('/pengaturan/pengguna', [
            'name' => 'Sena', 'email' => 'sena@master.test', 'role' => 'kasir_senior',
            'store_id' => $store->id, 'password' => 'password123',
        ])->assertRedirect()->assertSessionHas('success');

        $sena = User::where('email', 'sena@master.test')->firstOrFail();
        $this->actingAs($sena)->get('/laporan')->assertOk();
        $this->actingAs($sena)->get('/produk')->assertForbidden();
        $this->actingAs($sena)->get('/kasir')->assertOk();
    }

    public function test_new_role_cannot_reach_other_stores(): void
    {
        ['tenant' => $tenant, 'store' => $store, 'superadmin' => $superadmin] = $this->setupWarung();
        $other = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Dua', 'code' => 'CB2', 'is_active' => true]);

        $this->actingAs($superadmin)->post('/pengaturan/role', [
            'key' => 'kasir_senior', 'name' => 'Kasir Senior', 'modules' => ['pos', 'transactions'],
        ])->assertRedirect();
        $sena = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Sena', 'email' => 'sena2@master.test',
            'role' => 'kasir_senior', 'is_active' => true, 'password' => 'password',
        ]);

        $this->actingAs($sena)->post('/switch-store', ['store_id' => $other->id])->assertForbidden();
        $this->actingAs($sena)->post('/switch-store', ['store_id' => 'consolidated'])->assertForbidden();
    }

    public function test_role_key_must_be_unique_and_url_safe(): void
    {
        ['superadmin' => $superadmin] = $this->setupWarung();

        $this->actingAs($superadmin)->post('/pengaturan/role', ['key' => 'cashier', 'name' => 'Duplikat', 'modules' => ['pos']])
            ->assertSessionHasErrors('key');
        $this->actingAs($superadmin)->post('/pengaturan/role', ['key' => 'Kasir Baru', 'name' => 'Salah', 'modules' => ['pos']])
            ->assertSessionHasErrors('key');
        $this->actingAs($superadmin)->post('/pengaturan/role', ['key' => 'kasir_baru', 'name' => 'Benar', 'modules' => []])
            ->assertSessionHasErrors('modules');
    }

    public function test_unknown_module_is_rejected_and_order_is_normalised(): void
    {
        ['tenant' => $tenant, 'superadmin' => $superadmin] = $this->setupWarung();
        $cashierRole = $this->roleKey($tenant->id, 'cashier');

        $this->actingAs($superadmin)->put("/pengaturan/role/{$cashierRole->id}", [
            'name' => 'Kasir', 'modules' => ['pos', 'modul_palsu'],
        ])->assertSessionHasErrors('modules.1');

        $this->actingAs($superadmin)->put("/pengaturan/role/{$cashierRole->id}", [
            'name' => 'Kasir', 'modules' => ['members', 'pos', 'transactions'],
        ])->assertRedirect();
        // Disimpan urut seperti menu, bukan urutan kiriman form.
        $this->assertSame(['pos', 'transactions', 'members'], $cashierRole->fresh()->modules);
    }

    public function test_only_superadmin_can_manage_the_role_master(): void
    {
        ['tenant' => $tenant, 'store' => $store] = $this->setupWarung();
        $cashierRole = $this->roleKey($tenant->id, 'cashier');
        $headOps = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Raka', 'email' => 'raka@master.test',
            'role' => 'head_ops', 'is_active' => true, 'password' => 'password',
        ]);

        // Head of Ops tidak punya modul Pengaturan, jadi tertutup di lapis route.
        $this->actingAs($headOps)->get('/pengaturan')->assertForbidden();
        $this->actingAs($headOps)->put("/pengaturan/role/{$cashierRole->id}", ['name' => 'X', 'modules' => ['pos']])->assertForbidden();
        $this->actingAs($headOps)->post('/pengaturan/role', ['key' => 'baru', 'name' => 'X', 'modules' => ['pos']])->assertForbidden();
        $this->actingAs($headOps)->delete("/pengaturan/role/{$cashierRole->id}")->assertForbidden();
    }

    public function test_role_of_another_tenant_is_not_reachable(): void
    {
        ['superadmin' => $superadmin] = $this->setupWarung();
        $otherTenant = Tenant::create(['name' => 'Warung Lain', 'slug' => 'warung-lain']);
        Role::provisionDefaults($otherTenant->id);
        $foreignRole = $this->roleKey($otherTenant->id, 'cashier');

        $this->actingAs($superadmin)->put("/pengaturan/role/{$foreignRole->id}", ['name' => 'X', 'modules' => ['pos']])->assertNotFound();
        $this->actingAs($superadmin)->delete("/pengaturan/role/{$foreignRole->id}")->assertNotFound();
    }

    public function test_settings_page_shows_the_role_matrix(): void
    {
        ['superadmin' => $superadmin] = $this->setupWarung();

        $this->actingAs($superadmin)->get('/pengaturan')
            ->assertOk()
            ->assertSeeText('Master role & hak akses menu', false)
            ->assertSeeText('Tambah role')
            ->assertSee('name="modules[]"', false)
            ->assertViewHas('roles', fn ($roles) => $roles->count() === 6);
    }
}
