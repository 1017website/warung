<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_daily_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('record_date');
            $table->decimal('opening_quantity', 15, 3)->default(0);
            $table->decimal('used_quantity', 15, 3)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('opening_is_manual')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'product_id', 'record_date']);
            $table->index(['tenant_id', 'store_id', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_daily_records');
    }
};
