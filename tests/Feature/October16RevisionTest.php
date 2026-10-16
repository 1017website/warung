<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class October16RevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_uses_app_view_in_indonesian_without_tailwind_dark_mode(): void
    {
        $tenant = Tenant::create(['name' => 'Warung Oktober', 'slug' => 'warung-oktober']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        $owner = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Superadmin', 'email' => 'owner16@test.id',
            'role' => User::SUPERADMIN, 'is_active' => true, 'password' => 'password',
        ]);
        foreach (range(1, 45) as $i) {
            Product::create([
                'tenant_id' => $tenant->id, 'name' => 'Produk '.$i, 'product_type' => 'ingredient', 'sku' => 'P-'.$i,
                'unit' => 'pcs', 'purchase_price' => 0, 'selling_price' => 0, 'minimum_stock' => 0,
            ]);
        }

        $this->actingAs($owner)->withSession(['store_id' => $store->id])->get('/produk?page=2')->assertOk()
            ->assertSee('class="pager"', false)
            ->assertSeeText('Halaman 2 dari 3')
            ->assertSee('aria-label="Sebelumnya"', false)
            ->assertSee('aria-label="Berikutnya"', false)
            ->assertDontSeeText('Showing')
            ->assertDontSee('dark:bg-gray-800', false);
    }
}
