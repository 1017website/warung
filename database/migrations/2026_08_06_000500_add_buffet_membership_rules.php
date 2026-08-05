<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('member_discount_percent', 5, 2)->default(10)->after('non_real_percentage');
        });

        Schema::table('product_stocks', fn (Blueprint $table) => $table->decimal('quantity', 15, 3)->default(0)->change());
        Schema::table('daily_menu_stocks', fn (Blueprint $table) => $table->decimal('quantity', 15, 3)->default(0)->change());
        Schema::table('transaction_items', fn (Blueprint $table) => $table->decimal('quantity', 15, 3)->change());
        Schema::table('purchase_items', fn (Blueprint $table) => $table->decimal('quantity', 15, 3)->change());
        Schema::table('stock_movements', fn (Blueprint $table) => $table->decimal('quantity', 15, 3)->change());
        Schema::table('stock_productions', fn (Blueprint $table) => $table->decimal('output_quantity', 15, 3)->change());
    }

    public function down(): void
    {
        Schema::table('stock_productions', fn (Blueprint $table) => $table->integer('output_quantity')->change());
        Schema::table('stock_movements', fn (Blueprint $table) => $table->integer('quantity')->change());
        Schema::table('purchase_items', fn (Blueprint $table) => $table->integer('quantity')->change());
        Schema::table('transaction_items', fn (Blueprint $table) => $table->integer('quantity')->change());
        Schema::table('daily_menu_stocks', fn (Blueprint $table) => $table->integer('quantity')->default(0)->change());
        Schema::table('product_stocks', fn (Blueprint $table) => $table->integer('quantity')->default(0)->change());
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('member_discount_percent'));
    }
};
