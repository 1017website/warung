<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->orderBy('id')->pluck('id')
            ->each(fn ($tenantId) => Role::provisionDefaults((int) $tenantId));

        $email = trim((string) config('services.developer.email'));
        $password = (string) config('services.developer.password');
        if ($email === '' || $password === '') {
            return;
        }

        // Fresh database pengujian/deploy dapat belum memiliki tenant sama sekali.
        // Akun baru diprovisikan setelah tenant aplikasi tersedia.
        if (Tenant::count() === 0) {
            return;
        }

        $tenantSlug = trim((string) config('services.developer.tenant_slug'));
        $tenant = $tenantSlug !== ''
            ? Tenant::where('slug', $tenantSlug)->first()
            : (Tenant::count() === 1 ? Tenant::first() : null);

        if (! $tenant) {
            throw new RuntimeException('Tenant akun developer tidak dapat ditentukan. Isi DEVELOPER_TENANT_SLUG di .env.');
        }

        $storeCode = trim((string) config('services.developer.store_code'));
        $storeQuery = Store::where('tenant_id', $tenant->id)->where('is_active', true);
        $store = $storeCode !== '' ? $storeQuery->where('code', $storeCode)->first() : $storeQuery->orderBy('id')->first();
        if (! $store) {
            throw new RuntimeException('Cabang akun developer tidak dapat ditentukan. Isi DEVELOPER_STORE_CODE di .env.');
        }

        $developer = User::withTrashed()->firstOrNew(['email' => $email]);
        $developer->fill([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'name' => (string) config('services.developer.name', 'Developer 1017 Website'),
            'role' => User::DEVELOPER,
            'is_active' => true,
            'password' => Hash::make($password),
        ]);
        $developer->deleted_at = null;
        $developer->save();
    }

    public function down(): void
    {
        User::where('role', User::DEVELOPER)->delete();
        Role::where('key', User::DEVELOPER)->delete();
    }
};
