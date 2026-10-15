<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi 16 Oktober 2026: semua pengguna boleh live edit kolom stok (tambahan olahan,
 * konsumsi, diproses ulang, dll.), jadi setiap perubahan dicatat siapa, kapan, kolom
 * apa, dan nilai sebelum/sesudahnya.
 *
 * Setiap langkah memeriksa skema lebih dulu agar aman dijalankan ulang dari /maintenance/migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_edit_logs')) {
            Schema::create('inventory_edit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('field', 40);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('reason')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'store_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_edit_logs');
    }
};
