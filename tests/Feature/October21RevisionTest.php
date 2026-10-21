<?php

namespace Tests\Feature;

use App\Models\InventoryEditLog;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Revisi 21 Oktober 2026: stok datang bisa diedit, pesan error 422 di semua modul, omzet tersembunyi dari kasir. */
class October21RevisionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung 21', 'slug' => 'warung-21']);
        Role::provisionDefaults($tenant->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pawon', 'code' => 'PWN', 'is_active' => true]);
        $cashier = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Kasir', 'email' => 'kasir@21.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password']);
        $spv = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'SPV', 'email' => 'spv@21.test', 'role' => 'spv', 'is_active' => true, 'password' => 'password', 'authorization_pin' => Hash::make('1234')]);
        $raw = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Sukun', 'product_type' => 'ingredient', 'sku' => 'KR-58',
            'unit' => 'buah', 'purchase_price' => 0, 'selling_price' => 0, 'minimum_stock' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 10]);

        return compact('tenant', 'store', 'cashier', 'spv', 'raw');
    }

    private function balance(Product $product, Store $store): float
    {
        return (float) ProductStock::where('store_id', $store->id)->where('product_id', $product->id)->value('quantity');
    }

    public function test_incoming_stock_is_live_editable_as_daily_total(): void
    {
        ['store' => $store, 'cashier' => $cashier, 'raw' => $raw] = $this->setupWarung();
        $as = fn () => $this->actingAs($cashier)->withSession(['store_id' => $store->id]);

        $as()->get('/gudang')->assertOk()->assertSee('name="incoming_quantity"', false);

        $as()->patchJson("/gudang/stock/{$raw->id}", ['incoming_quantity' => 5])->assertOk()->assertJsonPath('data.quantity', fn ($v) => (float) $v === 15.0);
        $this->assertEquals(15, $this->balance($raw, $store));

        // Nilai yang diketik adalah total hari ini: koreksi 5 -> 2 mengembalikan 3.
        $as()->patchJson("/gudang/stock/{$raw->id}", ['incoming_quantity' => 2])->assertOk();
        $this->assertEquals(12, $this->balance($raw, $store));
        $as()->get('/gudang')->assertOk()->assertSee('name="incoming_quantity" value="2"', false);

        // Pembelian / penyesuaian masuk ikut terhitung di total stok datang.
        $as()->post('/gudang/adjust', ['product_id' => $raw->id, 'type' => 'adjustment_in', 'quantity' => 4, 'notes' => 'Kiriman pusat'])->assertSessionHasNoErrors();
        $as()->get('/gudang')->assertOk()->assertSee('name="incoming_quantity" value="6"', false);
        $as()->patchJson("/gudang/stock/{$raw->id}", ['incoming_quantity' => 6])->assertOk();
        $this->assertEquals(16, $this->balance($raw, $store));

        $this->assertSame(['5', '2'], InventoryEditLog::where('field', 'incoming_quantity')->orderBy('id')->pluck('new_value')->map(fn ($v) => (string) (float) $v)->all());
        $as()->get('/gudang')->assertSee('Stok datang');

        // Stok tidak boleh minus karena koreksi stok datang.
        $as()->patchJson("/gudang/stock/{$raw->id}", ['used_quantity' => 16])->assertOk();
        $as()->patchJson("/gudang/stock/{$raw->id}", ['incoming_quantity' => 0])->assertStatus(422)
            ->assertJsonPath('message', 'Koreksi stok datang membuat sisa stok negatif.');
        $this->assertEquals(0, $this->balance($raw, $store));
    }

    public function test_business_errors_on_regular_forms_return_with_message_in_every_module(): void
    {
        ['store' => $store, 'cashier' => $cashier] = $this->setupWarung();

        // Tutup kasir tanpa PIN langsung tersimpan; tidak ada kolom PIN di form.
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->get('/kasir/tutup-harian')->assertOk()
            ->assertDontSee('name="approval_pin"', false)->assertDontSee('PIN Manager/SPV');
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->from('/kasir/tutup-harian')
            ->post('/kasir/tutup-harian', ['opening_cash' => 50000, 'actual_cash' => 50000])
            ->assertRedirect('/kasir/tutup-harian')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cashier_closings', ['store_id' => $store->id, 'user_id' => $cashier->id, 'authorized_by' => null]);

        // Aturan bisnis yang menolak form kembali ke halaman asal dengan pesan dan isian tetap.
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->from('/kasir/tutup-harian')
            ->post('/kasir/tutup-harian', ['opening_cash' => 60000, 'actual_cash' => 60000])
            ->assertRedirect('/kasir/tutup-harian')
            ->assertSessionHasErrors()
            ->assertSessionHasInput('opening_cash', 60000);
        $this->followRedirects($this->actingAs($cashier)->from('/kasir/tutup-harian')
            ->post('/kasir/tutup-harian', ['opening_cash' => 60000, 'actual_cash' => 60000]))
            ->assertOk()->assertSee('Tutup kasir hari ini sudah tersimpan.')->assertDontSee('Oops');

        // Halaman GET yang menolak dirinya sendiri tidak berputar ke URL yang sama.
        $pending = Transaction::create([
            'tenant_id' => $cashier->tenant_id, 'store_id' => $store->id, 'user_id' => $cashier->id, 'invoice_no' => 'INV-PEND', 'status' => 'pending',
            'transaction_type' => 'sale', 'report_type' => 'real', 'service_type' => 'takeaway', 'subtotal' => 0, 'total' => 0,
            'payment_method' => 'cash', 'paid_amount' => 0, 'change_amount' => 0, 'transacted_at' => now(),
        ]);
        $url = url("/transaksi/{$pending->id}/epos");
        $this->actingAs($cashier)->from($url)->get($url)
            ->assertRedirect(route($cashier->landingRoute()))->assertSessionHasErrors();

        // Pemanggil JSON tetap menerima 422.
        $this->actingAs($cashier)->getJson($url)->assertStatus(422);
    }

    public function test_cashier_cannot_see_turnover(): void
    {
        ['store' => $store, 'cashier' => $cashier, 'spv' => $spv] = $this->setupWarung();
        $this->assertFalse($cashier->canSeeRevenue());

        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->get('/kasir/tutup-harian')->assertOk()
            ->assertDontSee('Omzet')->assertSee('Transaksi hari ini');
        $this->actingAs($cashier)->withSession(['store_id' => $store->id])->get('/laporan/produk-terjual/cetak?paper=a4')->assertOk()
            ->assertDontSee('Omzet')->assertViewHas('showRevenue', false);
    }
}
