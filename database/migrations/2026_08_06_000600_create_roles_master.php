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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30);
            $table->string('name', 60);
            $table->string('summary', 120)->nullable();
            $table->json('modules');
            $table->boolean('can_access_all_stores')->default(false);
            $table->boolean('can_see_non_real')->default(false);
            $table->boolean('is_supervisor')->default(false);
            // Role sistem (Superadmin) tidak dapat diubah hak aksesnya maupun dihapus.
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'position']);
        });

        // Data awal = tiering yang berlaku sekarang, untuk setiap tenant yang sudah ada.
        DB::table('tenants')->orderBy('id')->pluck('id')
            ->each(fn ($tenantId) => Role::provisionDefaults((int) $tenantId));
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
