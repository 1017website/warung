<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'modules' => 'array',
        'can_access_all_stores' => 'boolean',
        'can_see_non_real' => 'boolean',
        'is_supervisor' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** Modul yang dapat dicentang Superadmin, urut seperti menu. */
    public const MODULES = [
        'dashboard' => 'Ringkasan',
        'pos' => 'Kasir',
        'transactions' => 'Transaksi',
        'products' => 'Produk',
        'inventory' => 'Stok / Gudang',
        'purchases' => 'Pembelian',
        'expenses' => 'Pengeluaran',
        'members' => 'Membership',
        'reports' => 'Laporan',
        'settings' => 'Pengaturan',
    ];

    /** Modul yang memperlihatkan omzet; dipakai untuk label "gaboleh lihat omset". */
    public const REVENUE_MODULES = ['dashboard', 'reports'];

    /**
     * Data awal master role, disalin dari tabel TIERING AKSES pada POINT REVISION.docx.
     * Dipakai migrasi dan seeder; setelah itu Superadmin bebas mengubah lewat Pengaturan.
     */
    public const DEFAULTS = [
        [
            'key' => 'superadmin',
            'name' => 'Superadmin',
            'summary' => 'Semua fitur & akun',
            'modules' => ['dashboard', 'pos', 'transactions', 'products', 'inventory', 'purchases', 'expenses', 'members', 'reports', 'settings'],
            'can_access_all_stores' => true,
            'can_see_non_real' => true,
            'is_supervisor' => true,
            'is_system' => true,
        ],
        [
            'key' => 'head_ops',
            'name' => 'Head of Ops',
            'summary' => 'Operasional lengkap + laporan',
            'modules' => ['dashboard', 'pos', 'transactions', 'products', 'inventory', 'purchases', 'expenses', 'members', 'reports'],
            'can_access_all_stores' => true,
            'can_see_non_real' => false,
            'is_supervisor' => true,
            'is_system' => false,
        ],
        [
            'key' => 'ops_admin',
            'name' => 'Ops Admin',
            'summary' => 'Operasional tanpa omzet',
            'modules' => ['pos', 'transactions', 'products', 'inventory', 'purchases', 'expenses', 'members'],
            'can_access_all_stores' => false,
            'can_see_non_real' => false,
            'is_supervisor' => false,
            'is_system' => false,
        ],
        [
            'key' => 'outlet_manager',
            'name' => 'Outlet Manager',
            'summary' => 'Kasir, transaksi, member, stok, pengeluaran',
            'modules' => ['pos', 'transactions', 'inventory', 'expenses', 'members'],
            'can_access_all_stores' => false,
            'can_see_non_real' => false,
            'is_supervisor' => true,
            'is_system' => false,
        ],
        [
            'key' => 'spv',
            'name' => 'SPV',
            'summary' => 'Kasir, transaksi, member, stok, pengeluaran',
            'modules' => ['pos', 'transactions', 'inventory', 'expenses', 'members'],
            'can_access_all_stores' => false,
            'can_see_non_real' => false,
            'is_supervisor' => true,
            'is_system' => false,
        ],
        [
            'key' => 'cashier',
            'name' => 'Kasir',
            'summary' => 'Kasir, transaksi, member, stok, pengeluaran',
            'modules' => ['pos', 'transactions', 'inventory', 'expenses', 'members'],
            'can_access_all_stores' => false,
            'can_see_non_real' => false,
            'is_supervisor' => false,
            'is_system' => false,
        ],
    ];

    /** Isi master role bawaan untuk sebuah tenant; aman dipanggil berulang. */
    public static function provisionDefaults(int $tenantId): void
    {
        foreach (self::DEFAULTS as $position => $role) {
            self::withTrashed()->updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $role['key']],
                $role + ['position' => $position, 'deleted_at' => null]
            );
        }
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'key')->where('users.tenant_id', $this->tenant_id);
    }

    public function canAccess(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }

    public function canSeeRevenue(): bool
    {
        return (bool) array_intersect(self::REVENUE_MODULES, $this->modules ?? []);
    }

    /** Modul valid saja, urut seperti Role::MODULES, tanpa duplikat. */
    public static function sanitizeModules(array $modules): array
    {
        return array_values(array_filter(
            array_keys(self::MODULES),
            fn (string $module) => in_array($module, $modules, true)
        ));
    }
}
