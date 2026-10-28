<?php

namespace Tests\Feature;

use App\Models\DailyMenuStock;
use App\Models\InventoryDailyRecord;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Revisi 28 Oktober 2026: stok bahan baku & olahan per tanggal (ekspor back date) dan sesi login lebih lama. */
class October28RevisionTest extends TestCase
{
    use RefreshDatabase;

    private function exportRows(User $admin, Store $store, ?string $date = null): array
    {
        $response = $this->actingAs($admin)->withSession(['store_id' => $store->id])->get(route('inventory.export', array_filter(['tanggal' => $date])))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        return [$book->getSheetByName('Bahan Baku')->toArray(), $book->getSheetByName('Olahan')->toArray(), $response->headers->get('content-disposition')];
    }

    public function test_stock_page_and_export_can_be_back_dated_without_touching_history(): void
    {
        $yesterday = today()->subDay();
        $this->travelTo($yesterday->copy()->setTime(10, 0));
        $tenant = Tenant::create(['name' => 'Warung Rev', 'slug' => 'warung-rev']);
        Role::provisionDefaults($tenant->id);
        $wts = Store::create(['tenant_id' => $tenant->id, 'name' => 'WTS', 'code' => 'WTS', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'name' => 'Admin', 'email' => 'admin@rev.test', 'role' => 'superadmin', 'is_active' => true, 'password' => 'password']);
        $menu = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Nasi Ayam', 'product_type' => 'menu', 'sku' => 'NA-1',
            'unit' => 'porsi', 'purchase_price' => 8000, 'selling_price' => 20000, 'online_selling_price' => 20000, 'minimum_stock' => 2, 'is_active' => true,
        ]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 20]);
        $jahe = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Jahe', 'product_type' => 'ingredient', 'sku' => '007',
            'unit' => 'kg', 'purchase_price' => 1000, 'selling_price' => 0, 'minimum_stock' => 1, 'is_active' => true,
        ]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $wts->id, 'product_id' => $jahe->id, 'quantity' => 10]);

        // Kemarin: stok datang 5 kg jahe, konsumsi 3 porsi nasi ayam.
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang')->assertOk();
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->post('/gudang/adjust', ['product_id' => $jahe->id, 'type' => 'adjustment_in', 'quantity' => 5, 'notes' => 'Datang'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->post('/gudang/adjust', ['product_id' => $menu->id, 'type' => 'consumption', 'quantity' => 3, 'notes' => 'Makan karyawan'])->assertSessionHasNoErrors();

        // Hari ini: jahe keluar 4 kg.
        $this->travelBack();
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->post('/gudang/adjust', ['product_id' => $jahe->id, 'type' => 'adjustment_out', 'quantity' => 4, 'notes' => 'Koreksi'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang')->assertOk()
            ->assertSee(route('inventory.export', ['tanggal' => today()->toDateString()]), false)->assertDontSee('hanya baca');
        $recordsBefore = InventoryDailyRecord::count();

        [$raw, $olahan, $disposition] = $this->exportRows($admin, $wts, $yesterday->toDateString());
        $this->assertStringContainsString('stok-gudang-wts-'.$yesterday->format('Ymd'), $disposition);
        $this->assertSame(['007', 'Jahe', 'Bahan baku', 'kg', '10', '5', '0', '0', '0', '15', '1', 'Aman', null], $raw[1]);
        $this->assertSame(['NA-1', 'Nasi Ayam', 'Menu', 'porsi', '20', '0', '0', '3', '0', '0', '17'], array_slice($olahan[1], 0, 11));

        [$raw] = $this->exportRows($admin, $wts);
        $this->assertSame(['15', '11'], [$raw[1][4], $raw[1][9]]);

        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang?tanggal='.$yesterday->toDateString())->assertOk()
            ->assertSee('hanya baca')->assertSee($yesterday->translatedFormat('d F Y'))
            ->assertSee(route('inventory.export', ['tanggal' => $yesterday->toDateString()]), false);
        $this->assertSame($recordsBefore, InventoryDailyRecord::count());
        $this->assertSame(1, DailyMenuStock::whereDate('stock_date', $yesterday)->count());

        $this->actingAs($admin)->withSession(['store_id' => $wts->id])->get('/gudang/export?tanggal='.today()->addDay()->toDateString())->assertSessionHasErrors('tanggal');
    }

    public function test_login_session_lasts_a_week_and_remember_me_is_preselected(): void
    {
        $this->assertGreaterThanOrEqual(10080, config('session.lifetime'));
        $this->get('/login')->assertOk()->assertSee('name="remember" checked', false);
    }
}
