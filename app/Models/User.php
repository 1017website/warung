<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Kunci role bawaan. Daftar role dan hak akses menunya kini tersimpan di master
     * tabel `roles` (App\Models\Role) dan dapat diubah Superadmin lewat Pengaturan.
     */
    public const SUPERADMIN = 'superadmin';

    public const DEVELOPER = 'developer';

    public const HEAD_OPS = 'head_ops';

    public const OPS_ADMIN = 'ops_admin';

    public const OUTLET_MANAGER = 'outlet_manager';

    public const SPV = 'spv';

    public const CASHIER = 'cashier';

    /** Menu sidebar dan bottom nav: [modul, ikon, label]. */
    public const MENU = [
        ['dashboard', 'bi-grid-1x2', 'Ringkasan'],
        ['pos', 'bi-calculator', 'Kasir'],
        ['transactions', 'bi-receipt', 'Transaksi'],
        ['products', 'bi-box-seam', 'Produk'],
        ['inventory', 'bi-boxes', 'Stok / Gudang'],
        ['purchases', 'bi-bag-check', 'Pembelian'],
        ['expenses', 'bi-wallet2', 'Pengeluaran'],
        ['members', 'bi-people', 'Membership'],
        ['reports', 'bi-bar-chart', 'Laporan'],
        ['settings', 'bi-sliders', 'Pengaturan'],
    ];

    protected ?Role $roleDefinitionCache = null;

    protected bool $roleDefinitionResolved = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'store_id',
        'role',
        'is_active',
        'authorization_pin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'authorization_pin',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** Baris master role milik user ini; null bila rolenya tidak ada di master. */
    public function roleDefinition(): ?Role
    {
        if (! $this->roleDefinitionResolved) {
            $this->roleDefinitionCache = Role::where('tenant_id', $this->tenant_id)->where('key', $this->role)->first();
            $this->roleDefinitionResolved = true;
        }

        return $this->roleDefinitionCache;
    }

    /** Dipanggil setelah hak akses diubah agar pembacaan berikutnya memakai data baru. */
    public function forgetRoleDefinition(): void
    {
        $this->roleDefinitionCache = null;
        $this->roleDefinitionResolved = false;
    }

    public function canAccessAllStores(): bool
    {
        return (bool) $this->roleDefinition()?->can_access_all_stores;
    }

    public function isDeveloper(): bool
    {
        return $this->role === self::DEVELOPER;
    }

    public function canManageSystem(): bool
    {
        return in_array($this->role, [self::DEVELOPER, self::SUPERADMIN], true);
    }

    public function canRunMaintenance(): bool
    {
        if ($this->isDeveloper()) {
            return true;
        }

        if ($this->role !== self::SUPERADMIN) {
            return false;
        }

        // Masa transisi: Superadmin tetap dapat melakukan pemeliharaan sampai
        // setidaknya satu akun Developer aktif tersedia pada tenant yang sama.
        return ! self::where('tenant_id', $this->tenant_id)
            ->where('role', self::DEVELOPER)
            ->where('is_active', true)
            ->exists();
    }

    public function canAccessStore(int|string|null $storeId): bool
    {
        return $this->canAccessAllStores() || ((int) $storeId === (int) $this->store_id && $storeId !== null);
    }

    public function canAccess(string $module): bool
    {
        abort_unless(array_key_exists($module, Role::MODULES), 500, "Modul [{$module}] tidak dikenal.");

        return (bool) $this->roleDefinition()?->canAccess($module);
    }

    public function isSupervisor(): bool
    {
        return (bool) $this->roleDefinition()?->is_supervisor;
    }

    /** Stok awal hanya dapat dikoreksi oleh jenjang operasional yang berwenang. */
    public function canManageOpeningStock(): bool
    {
        return in_array($this->role, [
            self::DEVELOPER,
            self::SUPERADMIN,
            self::HEAD_OPS,
            self::OPS_ADMIN,
        ], true);
    }

    public function canSeeRevenue(): bool
    {
        return (bool) $this->roleDefinition()?->canSeeRevenue();
    }

    public function canSeeNonRealReport(): bool
    {
        return (bool) $this->roleDefinition()?->can_see_non_real;
    }

    public function roleLabel(): string
    {
        return $this->roleDefinition()?->name ?? str_replace('_', ' ', (string) $this->role);
    }

    /** Menu yang boleh dilihat user ini, siap dipakai layout. */
    public function menu(): array
    {
        $modules = $this->roleDefinition()?->modules ?? [];

        return array_values(array_filter(self::MENU, fn (array $item) => in_array($item[0], $modules, true)));
    }
}
