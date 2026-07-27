<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 20)->default('menu')->after('name')->index();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('service_type', 20)->default('dine_in')->after('invoice_no')->index();
            $table->string('table_number', 20)->nullable()->after('service_type');
            $table->string('online_platform', 30)->nullable()->after('table_number');
        });

        Schema::create('daily_menu_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('stock_date');
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'product_id', 'stock_date']);
            $table->index(['tenant_id', 'store_id', 'stock_date']);
        });

        DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('products.product_type', 'menu')
            ->select('product_stocks.tenant_id', 'product_stocks.store_id', 'product_stocks.product_id', 'product_stocks.quantity')
            ->orderBy('product_stocks.id')
            ->each(function ($stock) {
                DB::table('daily_menu_stocks')->updateOrInsert(
                    ['store_id' => $stock->store_id, 'product_id' => $stock->product_id, 'stock_date' => now()->toDateString()],
                    ['tenant_id' => $stock->tenant_id, 'quantity' => $stock->quantity, 'created_at' => now(), 'updated_at' => now()]
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_menu_stocks');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropColumn(['service_type', 'table_number', 'online_platform']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn('product_type');
        });
    }
};
