<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi 14 Oktober 2026: pembulatan total di kasir per cabang (ke atas, ke bawah,
 * atau terdekat) ke kelipatan Rp100/500/1.000. Selisihnya disimpan di transaksi
 * agar struk dan laporan tetap dapat menjelaskan total yang dibayar.
 *
 * Setiap langkah memeriksa skema lebih dulu agar aman dijalankan ulang dari /maintenance/migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'rounding_mode')) {
            Schema::table('stores', fn (Blueprint $table) => $table->string('rounding_mode', 10)->default('none')->after('tax_types'));
        }
        if (! Schema::hasColumn('stores', 'rounding_unit')) {
            Schema::table('stores', fn (Blueprint $table) => $table->unsignedInteger('rounding_unit')->default(100)->after('rounding_mode'));
        }
        if (! Schema::hasColumn('transactions', 'rounding_amount')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->decimal('rounding_amount', 15, 2)->default(0)->after('tax_amount'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'rounding_amount')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('rounding_amount'));
        }
        foreach (['rounding_unit', 'rounding_mode'] as $column) {
            if (Schema::hasColumn('stores', $column)) {
                Schema::table('stores', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
