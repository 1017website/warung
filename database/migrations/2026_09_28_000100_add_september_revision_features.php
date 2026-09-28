<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi 28 September 2026: ikon menu, harga per warung, banyak harga per SKU,
 * stok rusak, inventaris peralatan, pajak & service, dan printer Epson ePOS.
 *
 * Setiap langkah memeriksa skema lebih dulu. MySQL tidak membungkus DDL dalam
 * transaksi, sehingga migration yang terhenti di tengah dapat dijalankan ulang
 * dari /maintenance/migrate tanpa error kolom/tabel ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIcons();
        $this->addPricing();
        $this->addWaste();
        $this->addAssets();
        $this->addTaxAndService();
        $this->addPrinterDrivers();
        $this->grantAssetModule();
    }

    private function addIcons(): void
    {
        foreach (['categories' => 'color', 'products' => 'name'] as $tableName => $after) {
            if (! Schema::hasColumn($tableName, 'icon')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->string('icon', 40)->nullable()->after($after));
            }
        }
    }

    private function addPricing(): void
    {
        if (! Schema::hasTable('product_store_prices')) {
            Schema::create('product_store_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->decimal('selling_price', 15, 2)->nullable();
                $table->decimal('online_selling_price', 15, 2)->nullable();
                $table->boolean('is_available')->default(true);
                $table->timestamps();
                $table->unique(['product_id', 'store_id']);
                $table->index(['tenant_id', 'store_id']);
            });
        }

        if (! Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('label', 60);
                $table->decimal('price', 15, 2);
                $table->decimal('online_price', 15, 2)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->index(['product_id', 'store_id']);
            });
        }

        if (! Schema::hasColumn('transaction_items', 'product_price_id')) {
            Schema::table('transaction_items', function (Blueprint $table) {
                $table->foreignId('product_price_id')->nullable()->after('product_id')->constrained('product_prices')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('transaction_items', 'price_label')) {
            Schema::table('transaction_items', fn (Blueprint $table) => $table->string('price_label', 60)->nullable()->after('product_name'));
        }
    }

    private function addWaste(): void
    {
        if (! Schema::hasColumn('inventory_daily_records', 'waste_quantity')) {
            Schema::table('inventory_daily_records', fn (Blueprint $table) => $table->decimal('waste_quantity', 15, 3)->default(0)->after('used_quantity'));
        }
    }

    private function addAssets(): void
    {
        if (! Schema::hasTable('inventory_assets')) {
            Schema::create('inventory_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('code', 40)->nullable();
                $table->string('name', 120);
                $table->string('category', 60);
                $table->string('unit', 20)->default('unit');
                $table->unsignedInteger('quantity_good')->default(0);
                $table->unsignedInteger('quantity_damaged')->default(0);
                $table->string('location', 80)->nullable();
                $table->date('purchase_date')->nullable();
                $table->decimal('purchase_price', 15, 2)->nullable();
                $table->text('notes')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['tenant_id', 'store_id']);
            });
        }

        if (! Schema::hasTable('inventory_asset_logs')) {
            Schema::create('inventory_asset_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inventory_asset_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 20);
                $table->text('summary');
                $table->timestamps();
                $table->index(['tenant_id', 'store_id', 'created_at']);
            });
        }
    }

    private function addTaxAndService(): void
    {
        $storeColumns = [
            'service_charge_percent' => fn (Blueprint $table) => $table->decimal('service_charge_percent', 5, 2)->default(0),
            'service_charge_types' => fn (Blueprint $table) => $table->json('service_charge_types')->nullable(),
            'tax_percent' => fn (Blueprint $table) => $table->decimal('tax_percent', 5, 2)->default(0),
            'tax_label' => fn (Blueprint $table) => $table->string('tax_label', 30)->default('Pajak'),
            'tax_types' => fn (Blueprint $table) => $table->json('tax_types')->nullable(),
        ];
        foreach ($storeColumns as $column => $definition) {
            if (! Schema::hasColumn('stores', $column)) {
                Schema::table('stores', $definition);
            }
        }

        $transactionColumns = [
            'service_charge_percent' => fn (Blueprint $table) => $table->decimal('service_charge_percent', 5, 2)->default(0)->after('discount'),
            'service_charge' => fn (Blueprint $table) => $table->decimal('service_charge', 15, 2)->default(0)->after('service_charge_percent'),
            'tax_percent' => fn (Blueprint $table) => $table->decimal('tax_percent', 5, 2)->default(0)->after('service_charge'),
            'tax_label' => fn (Blueprint $table) => $table->string('tax_label', 30)->nullable()->after('tax_percent'),
            'tax_amount' => fn (Blueprint $table) => $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_label'),
        ];
        foreach ($transactionColumns as $column => $definition) {
            if (! Schema::hasColumn('transactions', $column)) {
                Schema::table('transactions', $definition);
            }
        }
    }

    private function addPrinterDrivers(): void
    {
        if (! Schema::hasColumn('connected_devices', 'driver')) {
            Schema::table('connected_devices', fn (Blueprint $table) => $table->string('driver', 30)->default('generic')->after('type'));
        }
        if (! Schema::hasColumn('connected_devices', 'settings')) {
            Schema::table('connected_devices', fn (Blueprint $table) => $table->json('settings')->nullable()->after('connection'));
        }
    }

    /**
     * Role bawaan yang sudah mengelola stok ikut mendapat menu Inventaris. Role
     * sistem terkunci dari form Pengaturan sehingga harus diperbarui di sini.
     */
    private function grantAssetModule(): void
    {
        $keys = collect(Role::DEFAULTS)->filter(fn ($role) => in_array('assets', $role['modules'], true))->pluck('key');
        DB::table('roles')->whereIn('key', $keys)->orderBy('id')->get(['id', 'modules'])->each(function ($role) {
            $modules = json_decode((string) $role->modules, true) ?: [];
            if (in_array('assets', $modules, true)) {
                return;
            }
            $modules[] = 'assets';
            DB::table('roles')->where('id', $role->id)->update(['modules' => json_encode(Role::sanitizeModules($modules))]);
        });

        DB::table('roles')->whereIn('key', ['developer', 'superadmin'])->orderBy('id')->get(['id', 'settings_permissions'])->each(function ($role) {
            $permissions = json_decode((string) $role->settings_permissions, true) ?: [];
            if (! in_array('tax_service', $permissions, true)) {
                $permissions[] = 'tax_service';
                DB::table('roles')->where('id', $role->id)->update(['settings_permissions' => json_encode(Role::sanitizeSettingsPermissions($permissions))]);
            }
        });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'modules', 'settings_permissions'])->each(function ($role) {
            DB::table('roles')->where('id', $role->id)->update([
                'modules' => json_encode(array_values(array_diff(json_decode((string) $role->modules, true) ?: [], ['assets']))),
                'settings_permissions' => json_encode(array_values(array_diff(json_decode((string) $role->settings_permissions, true) ?: [], ['tax_service']))),
            ]);
        });

        Schema::table('connected_devices', fn (Blueprint $table) => $table->dropColumn(['driver', 'settings']));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn(['service_charge_percent', 'service_charge', 'tax_percent', 'tax_label', 'tax_amount']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['service_charge_percent', 'service_charge_types', 'tax_percent', 'tax_label', 'tax_types']));
        Schema::dropIfExists('inventory_asset_logs');
        Schema::dropIfExists('inventory_assets');
        Schema::table('inventory_daily_records', fn (Blueprint $table) => $table->dropColumn('waste_quantity'));
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_price_id');
            $table->dropColumn('price_label');
        });
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_store_prices');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('icon'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
