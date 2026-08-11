<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('business_name')->nullable()->after('phone');
            $table->string('logo_path')->nullable()->after('business_name');
            $table->boolean('allow_custom_amount')->default(false)->after('logo_path');
            $table->decimal('non_real_percentage', 5, 2)->default(50)->after('allow_custom_amount');
            $table->decimal('member_discount_percent', 5, 2)->default(10)->after('non_real_percentage');
            $table->string('receipt_header')->nullable()->after('member_discount_percent');
            $table->string('receipt_footer')->nullable()->after('receipt_header');
            $table->boolean('receipt_show_logo')->default(true)->after('receipt_footer');
            $table->boolean('receipt_sort_by_category')->default(true)->after('receipt_show_logo');
        });

        // Pertahankan perilaku lama saat upgrade: setiap cabang dimulai dengan
        // nilai global tenant, lalu setelah ini dapat diubah secara independen.
        DB::table('stores')->orderBy('id')->eachById(function ($store) {
            $tenant = DB::table('tenants')->where('id', $store->tenant_id)->first();
            if (! $tenant) {
                return;
            }

            DB::table('stores')->where('id', $store->id)->update([
                'business_name' => $tenant->name,
                'logo_path' => $tenant->logo_path,
                'allow_custom_amount' => $tenant->allow_custom_amount,
                'non_real_percentage' => $tenant->non_real_percentage,
                'member_discount_percent' => $tenant->member_discount_percent,
                'receipt_header' => $tenant->receipt_header,
                'receipt_footer' => $tenant->receipt_footer,
                'receipt_show_logo' => $tenant->receipt_show_logo,
                'receipt_sort_by_category' => $tenant->receipt_sort_by_category,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn([
            'business_name',
            'logo_path',
            'allow_custom_amount',
            'non_real_percentage',
            'member_discount_percent',
            'receipt_header',
            'receipt_footer',
            'receipt_show_logo',
            'receipt_sort_by_category',
        ]));
    }
};
