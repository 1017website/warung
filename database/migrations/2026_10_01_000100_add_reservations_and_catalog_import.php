<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi 1 Oktober 2026: reservasi meja + DP dan impor workbook menu/stok outlet.
 *
 * Workbook outlet memakai SKU bahan baku yang sama untuk menu matang
 * (mis. BA-04 = bahan "Ayam Karkas" sekaligus menu "Ayam Bakar Negeri").
 * Karena itu SKU cukup unik per jenis produk, dan menu menyimpan SKU bahannya.
 *
 * Setiap langkah memeriksa skema lebih dulu agar aman dijalankan ulang dari /maintenance/migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->allowSharedSkuAcrossTypes();
        $this->addReservations();
        $this->grantReservationModule();
    }

    private function allowSharedSkuAcrossTypes(): void
    {
        if (! Schema::hasColumn('products', 'ingredient_sku')) {
            Schema::table('products', fn (Blueprint $table) => $table->string('ingredient_sku', 50)->nullable()->after('sku')->index());
        }
        // Indeks baru dibuat lebih dulu: MySQL memerlukan indeks berawalan tenant_id untuk foreign key.
        if (! Schema::hasIndex('products', 'products_tenant_type_sku_unique')) {
            Schema::table('products', fn (Blueprint $table) => $table->unique(['tenant_id', 'product_type', 'sku'], 'products_tenant_type_sku_unique'));
        }
        if (Schema::hasIndex('products', 'products_tenant_id_sku_unique')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropUnique('products_tenant_id_sku_unique'));
        }
    }

    private function addReservations(): void
    {
        if (! Schema::hasTable('reservations')) {
            Schema::create('reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->string('code', 30)->unique();
                $table->string('customer_name', 120);
                $table->string('phone', 30)->nullable();
                $table->dateTime('reserved_at');
                $table->unsignedSmallInteger('guests')->default(1);
                $table->string('table_number', 20)->nullable();
                $table->text('notes')->nullable();
                $table->decimal('dp_amount', 15, 2)->default(0);
                $table->string('dp_method', 20)->nullable();
                $table->string('dp_provider', 80)->nullable();
                $table->timestamp('dp_paid_at')->nullable();
                $table->decimal('dp_used', 15, 2)->default(0);
                $table->boolean('dp_refunded')->default(false);
                $table->timestamp('dp_refunded_at')->nullable();
                $table->string('status', 20)->default('booked')->index();
                $table->text('cancel_reason')->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['tenant_id', 'store_id', 'reserved_at']);
            });
        }

        if (! Schema::hasColumn('cashier_closings', 'cash_reservation_dp')) {
            Schema::table('cashier_closings', fn (Blueprint $table) => $table->decimal('cash_reservation_dp', 15, 2)->default(0)->after('cash_topups'));
        }
    }

    /** Role bawaan yang memegang Kasir ikut mendapat menu Reservasi. */
    private function grantReservationModule(): void
    {
        $keys = collect(Role::DEFAULTS)->filter(fn ($role) => in_array('reservations', $role['modules'], true))->pluck('key');
        DB::table('roles')->whereIn('key', $keys)->orderBy('id')->get(['id', 'modules'])->each(function ($role) {
            $modules = json_decode((string) $role->modules, true) ?: [];
            if (in_array('reservations', $modules, true)) {
                return;
            }
            $modules[] = 'reservations';
            DB::table('roles')->where('id', $role->id)->update(['modules' => json_encode(Role::sanitizeModules($modules))]);
        });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'modules'])->each(function ($role) {
            DB::table('roles')->where('id', $role->id)->update([
                'modules' => json_encode(array_values(array_diff(json_decode((string) $role->modules, true) ?: [], ['reservations']))),
            ]);
        });
        if (Schema::hasColumn('cashier_closings', 'cash_reservation_dp')) {
            Schema::table('cashier_closings', fn (Blueprint $table) => $table->dropColumn('cash_reservation_dp'));
        }
        Schema::dropIfExists('reservations');
        if (! Schema::hasIndex('products', 'products_tenant_id_sku_unique')) {
            Schema::table('products', fn (Blueprint $table) => $table->unique(['tenant_id', 'sku']));
        }
        if (Schema::hasIndex('products', 'products_tenant_type_sku_unique')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropUnique('products_tenant_type_sku_unique'));
        }
        if (Schema::hasColumn('products', 'ingredient_sku')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['ingredient_sku']);
                $table->dropColumn('ingredient_sku');
            });
        }
    }
};
