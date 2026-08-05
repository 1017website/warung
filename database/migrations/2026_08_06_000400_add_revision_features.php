<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('allow_custom_amount')->default(false);
            $table->decimal('non_real_percentage', 5, 2)->default(50);
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->boolean('receipt_show_logo')->default(true);
            $table->boolean('receipt_sort_by_category')->default(true);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('authorization_pin')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('online_selling_price', 15, 2)->default(0)->after('selling_price');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('domicile')->nullable();
            $table->date('birth_date')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->index();
            $table->string('transaction_type', 20)->default('sale')->index();
            $table->string('discount_type', 20)->default('amount');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->text('cancel_reason')->nullable();
            $table->foreignId('void_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->string('category_name')->nullable();
            $table->boolean('is_custom')->default(false);
        });

        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->string('provider')->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->index(['method', 'created_at']);
        });

        Schema::table('deposit_transactions', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('dp_amount', 15, 2)->default(0);
            $table->timestamp('received_at')->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('activity', 30)->nullable()->index();
        });

        Schema::create('stock_productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('menu_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('ingredient_quantity', 12, 3);
            $table->integer('output_quantity');
            $table->date('production_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'store_id', 'production_date']);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('count_date');
            $table->decimal('expected_quantity', 12, 3);
            $table->decimal('actual_quantity', 12, 3);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'product_id', 'count_date']);
        });

        Schema::create('member_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_code');
            $table->string('qr_code')->unique();
            $table->string('status', 20)->default('available')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'member_code']);
        });

        Schema::create('connected_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30);
            $table->string('connection')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_devices');
        Schema::dropIfExists('member_cards');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_productions');

        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('activity'));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn(['payment_status', 'dp_amount', 'received_at']));
        Schema::table('deposit_transactions', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::dropIfExists('transaction_payments');
        Schema::table('transaction_items', fn (Blueprint $table) => $table->dropColumn(['category_name', 'is_custom']));
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_authorized_by');
            $table->dropColumn(['status', 'transaction_type', 'discount_type', 'discount_value', 'cancel_reason', 'voided_at']);
        });
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['domicile', 'birth_date', 'discount_percent']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('online_selling_price'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('authorization_pin'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['allow_custom_amount', 'non_real_percentage', 'receipt_header', 'receipt_footer', 'receipt_show_logo', 'receipt_sort_by_category']));
    }
};
