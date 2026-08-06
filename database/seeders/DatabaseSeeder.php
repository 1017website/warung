<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockProduction;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Warung Prasmanan Bu Ayu',
            'slug' => 'warung-prasmanan-bu-ayu',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'non_real_percentage' => 50,
            'member_discount_percent' => 10,
            'receipt_sort_by_category' => true,
        ]);

        Role::provisionDefaults($tenant->id);

        $melati = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Melati', 'code' => 'MLT-01', 'address' => 'Jl. Melati No. 17, Jakarta', 'phone' => '021-555-017', 'is_active' => true]);
        $kenanga = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Kenanga', 'code' => 'KNG-02', 'address' => 'Jl. Kenanga No. 8, Jakarta', 'phone' => '021-555-018', 'is_active' => true]);

        $password = Hash::make('password');
        $pin = Hash::make('1234');
        $accountRows = [
            ['Sistem Warung', 'superadmin@warungkita.id', 'superadmin', $melati, true],
            ['Raka Head of Ops', 'headops@warungkita.id', 'head_ops', $melati, true],
            ['Nina Ops Admin', 'opsadmin@warungkita.id', 'ops_admin', $melati, false],
            ['Dewi Outlet Manager Melati', 'manager.melati@warungkita.id', 'outlet_manager', $melati, true],
            ['Dimas SPV Melati', 'spv.melati@warungkita.id', 'spv', $melati, true],
            ['Bima Kasir Melati', 'kasir.melati@warungkita.id', 'cashier', $melati, false],
            ['Rini Outlet Manager Kenanga', 'manager.kenanga@warungkita.id', 'outlet_manager', $kenanga, true],
            ['Seno SPV Kenanga', 'spv.kenanga@warungkita.id', 'spv', $kenanga, true],
            ['Tari Kasir Kenanga', 'kasir.kenanga@warungkita.id', 'cashier', $kenanga, false],
        ];

        $users = collect($accountRows)->map(function ($row) use ($tenant, $password, $pin) {
            [$name, $email, $role, $store, $needsPin] = $row;

            return User::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'is_active' => true,
                'password' => $password,
                'authorization_pin' => $needsPin ? $pin : null,
            ]);
        });
        $superadmin = $users->firstWhere('role', 'superadmin');

        $rawCategory = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bahan Baku', 'color' => '#8b7867']);
        $buffetCategory = Category::create(['tenant_id' => $tenant->id, 'name' => 'Lauk Prasmanan', 'color' => '#d39a59']);

        $productRows = [
            ['sku' => '001', 'barcode' => 'RAW-001', 'name' => 'Ayam Kampung', 'type' => 'ingredient', 'unit' => 'pcs', 'buy' => 65000, 'sell' => 0, 'online' => 0, 'minimum' => 20, 'category' => $rawCategory],
            ['sku' => '002', 'barcode' => 'RAW-002', 'name' => 'Udang', 'type' => 'ingredient', 'unit' => 'gram', 'buy' => 120, 'sell' => 0, 'online' => 0, 'minimum' => 100, 'category' => $rawCategory],
            ['sku' => 'OLH-001', 'barcode' => 'MENU-001', 'name' => 'Ayam Kampung Bakar', 'type' => 'menu', 'unit' => 'pcs', 'buy' => 15000, 'sell' => 25000, 'online' => 28000, 'minimum' => 2, 'category' => $buffetCategory],
            ['sku' => 'OLH-002G', 'barcode' => 'MENU-002', 'name' => 'Udang Goreng', 'type' => 'menu', 'unit' => 'gram', 'buy' => 120, 'sell' => 250, 'online' => 280, 'minimum' => 20, 'category' => $buffetCategory],
            ['sku' => 'OLH-002K', 'barcode' => 'MENU-003', 'name' => 'Udang Kuah', 'type' => 'menu', 'unit' => 'gram', 'buy' => 110, 'sell' => 220, 'online' => 250, 'minimum' => 20, 'category' => $buffetCategory],
        ];

        $products = collect($productRows)->mapWithKeys(function ($row) use ($tenant) {
            $product = Product::create([
                'tenant_id' => $tenant->id,
                'category_id' => $row['category']->id,
                'name' => $row['name'],
                'product_type' => $row['type'],
                'sku' => $row['sku'],
                'barcode' => $row['barcode'],
                'unit' => $row['unit'],
                'purchase_price' => $row['buy'],
                'selling_price' => $row['sell'],
                'online_selling_price' => $row['online'],
                'minimum_stock' => $row['minimum'],
                'is_active' => true,
            ]);

            return [$row['sku'] => $product];
        });

        foreach ([$melati, $kenanga] as $store) {
            ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $products['001']->id, 'quantity' => 90]);
            ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $products['002']->id, 'quantity' => 200]);

            foreach (['OLH-001' => 3, 'OLH-002G' => 40, 'OLH-002K' => 0] as $sku => $quantity) {
                DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $products[$sku]->id, 'stock_date' => today(), 'quantity' => $quantity]);
            }

            $movementRows = [
                ['001', 'purchase', 'purchase', 30, 'Stok datang sesuai Excel'],
                ['001', 'adjustment_out', 'stock_out', -30, 'Stok keluar untuk WKA'],
                ['001', 'adjustment_out', 'production', -10, 'Bahan untuk Ayam Kampung Bakar'],
                ['002', 'purchase', 'purchase', 50, 'Stok datang sesuai Excel'],
                ['002', 'adjustment_out', 'production', -200, 'Bahan untuk olahan udang'],
                ['OLH-001', 'adjustment_in', 'production', 10, 'Tambahan olahan'],
                ['OLH-001', 'sale', 'sale', -15, 'Terjual sebelum sistem diaktifkan'],
                ['OLH-001', 'adjustment_out', 'consumption', -2, 'Konsumsi owner 2 pcs'],
                ['OLH-002G', 'adjustment_in', 'production', 100, 'Tambahan olahan'],
                ['OLH-002G', 'sale', 'sale', -120, 'Terjual sebelum sistem diaktifkan'],
                ['OLH-002G', 'adjustment_out', 'consumption', -10, 'Konsumsi karyawan 10 gram'],
                ['OLH-002K', 'adjustment_in', 'production', 100, 'Tambahan olahan'],
                ['OLH-002K', 'sale', 'sale', -120, 'Terjual sebelum sistem diaktifkan'],
                ['OLH-002K', 'adjustment_out', 'consumption', -10, 'Konsumsi karyawan 10 gram'],
            ];
            foreach ($movementRows as [$sku, $type, $activity, $quantity, $notes]) {
                DB::table('stock_movements')->insert([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'product_id' => $products[$sku]->id,
                    'user_id' => $superadmin->id,
                    'type' => $type,
                    'activity' => $activity,
                    'quantity' => $quantity,
                    'reference' => 'SALDO-AWAL-EXCEL',
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ([
                ['001', 'OLH-001', 10, 10, 'Produksi awal sesuai Excel'],
                ['002', 'OLH-002G', 100, 100, 'Produksi awal sesuai Excel'],
                ['002', 'OLH-002K', 100, 100, 'Produksi awal sesuai Excel'],
            ] as [$ingredientSku, $menuSku, $ingredientQuantity, $outputQuantity, $notes]) {
                StockProduction::create([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'user_id' => $superadmin->id,
                    'ingredient_product_id' => $products[$ingredientSku]->id,
                    'menu_product_id' => $products[$menuSku]->id,
                    'ingredient_quantity' => $ingredientQuantity,
                    'output_quantity' => $outputQuantity,
                    'production_date' => today(),
                    'notes' => $notes,
                ]);
            }

            foreach ([
                ['OLH-001', 3, 2, 'Konsumsi owner 2 pcs'],
                ['OLH-002G', 40, 35, 'Konsumsi karyawan 10 gram'],
                ['OLH-002K', 0, 0, 'Konsumsi karyawan 10 gram'],
            ] as [$sku, $expected, $actual, $notes]) {
                StockCount::create([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'product_id' => $products[$sku]->id,
                    'user_id' => $superadmin->id,
                    'count_date' => today(),
                    'expected_quantity' => $expected,
                    'actual_quantity' => $actual,
                    'notes' => $notes,
                ]);
            }
        }

        foreach (range(1, 10) as $number) {
            $code = 'MBR-P'.str_pad($number, 5, '0', STR_PAD_LEFT);
            MemberCard::create([
                'tenant_id' => $tenant->id,
                'member_code' => $code,
                'qr_code' => 'WK-'.$code,
                'status' => 'available',
            ]);
        }

        // Database demo sengaja dimulai tanpa member, transaksi, pembelian, pengeluaran, atau deposit.
    }
}
