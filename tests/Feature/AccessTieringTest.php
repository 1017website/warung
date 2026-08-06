<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessTieringTest extends TestCase
{
    use RefreshDatabase;

    private const URLS = [
        'dashboard' => '/dashboard',
        'pos' => '/kasir',
        'transactions' => '/transaksi',
        'products' => '/produk',
        'inventory' => '/gudang',
        'purchases' => '/pembelian',
        'expenses' => '/pengeluaran',
        'members' => '/member',
        'reports' => '/laporan',
        'settings' => '/pengaturan',
    ];

    /**
     * Tabel TIERING AKSES pada POINT REVISION.docx, ditulis ulang dari dokumen
     * dan bukan dari kode, supaya jadi pembanding independen.
     */
    public static function tiering(): array
    {
        $operational = ['pos', 'transactions', 'members', 'inventory', 'expenses'];

        return [
            'Superadmin — semua fitur' => ['superadmin', array_keys(self::URLS)],
            'Head of Ops' => ['head_ops', [...$operational, 'purchases', 'products', 'reports', 'dashboard']],
            'Ops Admin — gaboleh lihat omset' => ['ops_admin', [...$operational, 'purchases', 'products']],
            'Outlet Manager — gaboleh lihat omset' => ['outlet_manager', $operational],
            'SPV — gaboleh lihat omset' => ['spv', $operational],
            'Kasir — gaboleh lihat omset' => ['cashier', $operational],
        ];
    }

    private function userWithRole(string $role): User
    {
        $tenant = Tenant::create(['name' => 'Warung Tiering '.$role, 'slug' => 'warung-tiering-'.str_replace('_', '-', $role)]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        Role::provisionDefaults($tenant->id);

        return User::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Uji '.$role,
            'email' => $role.'@tiering.test', 'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function roleOf(User $user): Role
    {
        return Role::where('tenant_id', $user->tenant_id)->where('key', $user->role)->firstOrFail();
    }

    #[DataProvider('tiering')]
    public function test_role_reaches_exactly_the_modules_listed_in_the_revision_document(string $role, array $allowed): void
    {
        $user = $this->userWithRole($role);

        foreach (self::URLS as $module => $url) {
            $response = $this->actingAs($user)->get($url);

            if (in_array($module, $allowed, true)) {
                $response->assertOk("Role {$role} seharusnya bisa membuka {$url}.");
            } else {
                $response->assertForbidden("Role {$role} seharusnya ditolak di {$url}.");
            }
        }
    }

    #[DataProvider('tiering')]
    public function test_sidebar_menu_matches_the_reachable_modules(string $role, array $allowed): void
    {
        $user = $this->userWithRole($role);
        $menu = array_column($user->menu(), 0);

        sort($menu);
        sort($allowed);
        $this->assertSame($allowed, $menu, "Menu sidebar role {$role} tidak sama dengan modul yang benar-benar bisa dibuka.");
    }

    public function test_only_superadmin_sees_non_real_report_and_can_manage_accounts(): void
    {
        foreach (array_column(Role::DEFAULTS, 'key') as $role) {
            $user = $this->userWithRole($role);
            $expected = $role === User::SUPERADMIN;

            $this->assertSame($expected, $user->canSeeNonRealReport(), "canSeeNonRealReport salah untuk {$role}.");
            $this->assertSame($expected, $user->canAccess('settings'), "Akses Pengaturan salah untuk {$role}.");
        }
    }

    public function test_supervisor_pin_holders_follow_the_document(): void
    {
        foreach (array_column(Role::DEFAULTS, 'key') as $role) {
            $user = $this->userWithRole($role);
            $this->assertSame(
                in_array($role, ['superadmin', 'head_ops', 'spv', 'outlet_manager'], true),
                $user->isSupervisor(),
                "isSupervisor salah untuk {$role}."
            );
        }
    }

    public function test_legacy_roles_are_rejected_at_login_instead_of_being_locked_out_silently(): void
    {
        foreach (['admin', 'owner', 'warehouse'] as $legacy) {
            $user = $this->userWithRole($legacy);

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect()
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    #[DataProvider('tiering')]
    public function test_login_page_sends_an_already_signed_in_user_to_a_page_they_can_open(string $role, array $allowed): void
    {
        $user = $this->userWithRole($role);
        $target = in_array('dashboard', $allowed, true) ? '/dashboard' : '/kasir';

        // Sebelumnya selalu diarahkan ke /dashboard sehingga role tanpa akses omset kena 403.
        $this->actingAs($user)->get('/login')->assertRedirect($target);
        $this->actingAs($user)->get($target)->assertOk();
    }

    #[DataProvider('tiering')]
    public function test_fresh_login_lands_on_a_page_the_role_can_open(string $role, array $allowed): void
    {
        $user = $this->userWithRole($role);
        $target = in_array('dashboard', $allowed, true) ? '/dashboard' : '/kasir';

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($target);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect($target);
    }

    public function test_unknown_module_fails_loudly(): void
    {
        $user = $this->userWithRole(User::SUPERADMIN);

        $this->expectException(HttpException::class);
        $user->canAccess('modul_yang_tidak_ada');
    }
}
