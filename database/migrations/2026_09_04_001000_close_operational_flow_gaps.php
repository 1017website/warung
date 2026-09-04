<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('settings_permissions')->nullable()->after('modules');
        });
        DB::table('roles')->update(['settings_permissions' => json_encode([])]);
        DB::table('roles')->whereIn('key', ['developer', 'superadmin'])->update([
            'settings_permissions' => json_encode(array_keys(Role::SETTINGS_PERMISSIONS)),
        ]);

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('cash')->after('amount');
        });

        Schema::table('connected_devices', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('status');
        });

        Schema::create('cashier_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('closing_date');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('cash_sales', 15, 2)->default(0);
            $table->decimal('cash_topups', 15, 2)->default(0);
            $table->decimal('cash_expenses', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('actual_cash', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->json('payment_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['store_id', 'closing_date']);
            $table->index(['tenant_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_closings');
        Schema::table('connected_devices', fn (Blueprint $table) => $table->dropColumn('last_tested_at'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('settings_permissions'));
    }
};
