<?php

namespace Tests\Feature;

use App\Models\DailyMenuStock;
use App\Models\Member;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membership sengaja dibagi antar warung: kartu, saldo deposit, dan diskon member
 * berlaku di semua cabang, sementara transaksi dan stoknya tetap tercatat per cabang.
 */
class MembershipAcrossStoresTest extends TestCase
{
    use RefreshDatabase;

    private function setupWarung(): array
    {
        $tenant = Tenant::create(['name' => 'Warung Member', 'slug' => 'warung-member', 'member_discount_percent' => 10]);
        Role::provisionDefaults($tenant->id);
        $melati = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Melati', 'code' => 'MLT', 'is_active' => true]);
        $kenanga = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Kenanga', 'code' => 'KNG', 'is_active' => true]);

        $kasirMelati = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $melati->id, 'name' => 'Kasir Melati',
            'email' => 'melati@member.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password',
        ]);
        $kasirKenanga = User::create([
            'tenant_id' => $tenant->id, 'store_id' => $kenanga->id, 'name' => 'Kasir Kenanga',
            'email' => 'kenanga@member.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'password',
        ]);

        $menu = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Rica Bebek', 'product_type' => 'menu', 'sku' => 'RB-1',
            'unit' => 'porsi', 'purchase_price' => 5000, 'selling_price' => 10000, 'minimum_stock' => 2,
        ]);
        foreach ([$melati, $kenanga] as $store) {
            DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today(), 'quantity' => 20]);
        }
        MemberCard::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-P00001', 'qr_code' => 'WK-MBR-P00001', 'status' => 'available']);

        return compact('tenant', 'melati', 'kenanga', 'kasirMelati', 'kasirKenanga', 'menu');
    }

    public function test_member_registered_at_one_warung_is_found_by_qr_at_another(): void
    {
        ['tenant' => $tenant, 'kasirMelati' => $kasirMelati, 'kasirKenanga' => $kasirKenanga] = $this->setupWarung();
        $card = MemberCard::where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($kasirMelati)->post('/member', [
            'name' => 'Rina', 'phone' => '0811', 'domicile' => 'Jakarta', 'birth_date' => '1995-04-01',
            'member_card_id' => $card->id,
        ])->assertRedirect()->assertSessionHas('success');

        $member = Member::firstOrFail();

        // Kasir cabang lain menemukan member yang sama lewat scan QR maupun kode member.
        $this->actingAs($kasirKenanga)->get('/member/find/'.$member->qr_code)
            ->assertOk()->assertJson(['id' => $member->id, 'name' => 'Rina']);
        $this->actingAs($kasirKenanga)->get('/member/find/'.$member->member_code)
            ->assertOk()->assertJson(['id' => $member->id]);

        // Daftar member juga sama di kedua cabang.
        $this->actingAs($kasirMelati)->get('/member')->assertOk()->assertSeeText('Rina');
        $this->actingAs($kasirKenanga)->get('/member')->assertOk()->assertSeeText('Rina');
    }

    public function test_deposit_topped_up_at_one_warung_is_spendable_at_another(): void
    {
        ['tenant' => $tenant, 'kenanga' => $kenanga, 'kasirMelati' => $kasirMelati, 'kasirKenanga' => $kasirKenanga, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create([
            'tenant_id' => $tenant->id, 'member_code' => 'MBR-1', 'qr_code' => 'qr-lintas',
            'name' => 'Rina', 'deposit_balance' => 0, 'discount_percent' => 10, 'is_active' => true,
        ]);

        // Top up di Melati.
        $this->actingAs($kasirMelati)->post("/member/{$member->id}/topup", ['amount' => 50000, 'payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 50000]);

        // Dipakai di Kenanga: 2 porsi = 20.000, diskon member 10% -> 18.000.
        $this->actingAs($kasirKenanga)->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 2]],
            'member_id' => $member->id, 'discount_type' => 'member', 'discount_value' => 0,
            'service_type' => 'takeaway',
            'payments' => [['method' => 'deposit', 'amount' => 18000]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 32000]);

        $transaction = Transaction::firstOrFail();
        $this->assertSame($kenanga->id, $transaction->store_id, 'Transaksi harus tercatat di cabang tempat belanja.');
        $this->assertEquals(2000, $transaction->discount);
        $this->assertEquals(18000, $transaction->total);

        // Jejak deposit menyimpan cabang masing-masing, jadi tetap bisa diaudit per warung.
        $this->assertDatabaseHas('deposit_transactions', ['member_id' => $member->id, 'type' => 'credit', 'amount' => 50000]);
        $this->assertDatabaseHas('deposit_transactions', ['member_id' => $member->id, 'type' => 'debit', 'amount' => 18000, 'store_id' => $kenanga->id]);

        // Stok yang terpotong hanya milik cabang yang melayani.
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $kenanga->id, 'product_id' => $menu->id, 'quantity' => 18]);
        $this->assertDatabaseHas('daily_menu_stocks', ['store_id' => $this->setupStoreIdOf($tenant->id, 'MLT'), 'product_id' => $menu->id, 'quantity' => 20]);
    }

    private function setupStoreIdOf(int $tenantId, string $code): int
    {
        return (int) Store::where('tenant_id', $tenantId)->where('code', $code)->value('id');
    }

    public function test_deposit_cannot_be_overspent_from_another_warung(): void
    {
        ['tenant' => $tenant, 'kasirKenanga' => $kasirKenanga, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create([
            'tenant_id' => $tenant->id, 'member_code' => 'MBR-2', 'qr_code' => 'qr-tipis',
            'name' => 'Budi', 'deposit_balance' => 5000, 'discount_percent' => 0, 'is_active' => true,
        ]);

        $this->actingAs($kasirKenanga)->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 2]],
            'member_id' => $member->id, 'service_type' => 'takeaway',
            'payments' => [['method' => 'deposit', 'amount' => 20000]],
        ])->assertStatus(422);

        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 5000]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_split_deposit_and_cash_works_from_a_second_warung(): void
    {
        ['tenant' => $tenant, 'kasirKenanga' => $kasirKenanga, 'menu' => $menu] = $this->setupWarung();
        $member = Member::create([
            'tenant_id' => $tenant->id, 'member_code' => 'MBR-3', 'qr_code' => 'qr-split',
            'name' => 'Sari', 'deposit_balance' => 15000, 'discount_percent' => 0, 'is_active' => true,
        ]);

        // Total 20.000: deposit 15.000 + tunai 5.000.
        $this->actingAs($kasirKenanga)->postJson('/kasir/checkout', [
            'items' => [['id' => $menu->id, 'qty' => 2]],
            'member_id' => $member->id, 'service_type' => 'takeaway',
            'payments' => [['method' => 'deposit', 'amount' => 15000], ['method' => 'cash', 'amount' => 5000]],
        ])->assertOk();

        $this->assertDatabaseHas('members', ['id' => $member->id, 'deposit_balance' => 0]);
        $transaction = Transaction::firstOrFail();
        $this->assertDatabaseHas('transaction_payments', ['transaction_id' => $transaction->id, 'method' => 'deposit', 'amount' => 15000]);
        $this->assertDatabaseHas('transaction_payments', ['transaction_id' => $transaction->id, 'method' => 'cash', 'amount' => 5000]);
    }

    public function test_precreated_card_can_be_activated_from_any_warung(): void
    {
        ['tenant' => $tenant, 'kasirKenanga' => $kasirKenanga] = $this->setupWarung();
        $card = MemberCard::where('tenant_id', $tenant->id)->firstOrFail();

        // Kartu dibuat terpusat, aktivasinya bisa dilakukan cabang mana pun.
        $this->actingAs($kasirKenanga)->get('/member/card/'.$card->qr_code)
            ->assertOk()->assertJson(['id' => $card->id, 'member_code' => $card->member_code]);

        $this->actingAs($kasirKenanga)->post('/member', ['name' => 'Tari', 'member_card_id' => $card->id])->assertRedirect();
        $this->assertDatabaseHas('member_cards', ['id' => $card->id, 'status' => 'assigned']);
        $this->assertDatabaseHas('members', ['member_code' => $card->member_code, 'name' => 'Tari']);
    }

    public function test_member_of_another_tenant_stays_invisible(): void
    {
        ['kasirKenanga' => $kasirKenanga] = $this->setupWarung();
        $otherTenant = Tenant::create(['name' => 'Warung Sebelah', 'slug' => 'warung-sebelah']);
        $foreignMember = Member::create([
            'tenant_id' => $otherTenant->id, 'member_code' => 'MBR-X', 'qr_code' => 'qr-asing',
            'name' => 'Member Tenant Lain', 'deposit_balance' => 90000, 'is_active' => true,
        ]);

        $this->actingAs($kasirKenanga)->get('/member/find/'.$foreignMember->qr_code)->assertNotFound();
        $this->actingAs($kasirKenanga)->get('/member')->assertOk()->assertDontSeeText('Member Tenant Lain');
        $this->actingAs($kasirKenanga)->post("/member/{$foreignMember->id}/topup", ['amount' => 10000])->assertNotFound();
    }
}
