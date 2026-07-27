<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create(['name' => 'Warung Makan Bu Ayu', 'slug' => 'warung-makan-bu-ayu', 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $main = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Melati', 'code' => 'MLT-01', 'address' => 'Jl. Melati No. 17, Jakarta', 'phone' => '021-555-017', 'is_active' => true]);
        $branch = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Kenanga', 'code' => 'KNG-02', 'address' => 'Jl. Kenanga No. 8, Jakarta', 'phone' => '021-555-018', 'is_active' => true]);

        $owner = User::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'name' => 'Ayu Pratama', 'email' => 'admin@warungkita.id', 'role' => 'owner', 'is_active' => true, 'password' => Hash::make('password')]);
        User::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'name' => 'Sistem Warung', 'email' => 'superadmin@warungkita.id', 'role' => 'superadmin', 'is_active' => true, 'password' => Hash::make('password')]);
        User::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'name' => 'Dewi Manager', 'email' => 'manager@warungkita.id', 'role' => 'admin', 'is_active' => true, 'password' => Hash::make('password')]);
        User::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'name' => 'Bima Kasir', 'email' => 'kasir@warungkita.id', 'role' => 'cashier', 'is_active' => true, 'password' => Hash::make('password')]);
        User::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'name' => 'Sari Gudang', 'email' => 'gudang@warungkita.id', 'role' => 'warehouse', 'is_active' => true, 'password' => Hash::make('password')]);

        $categories = collect([
            ['name' => 'Makanan Utama', 'color' => '#637ba8'],
            ['name' => 'Lauk & Tambahan', 'color' => '#d39a59'],
            ['name' => 'Minuman', 'color' => '#6d9d99'],
            ['name' => 'Camilan', 'color' => '#9a87ad'],
            ['name' => 'Bahan Baku', 'color' => '#8b7867'],
        ])->map(fn ($data) => Category::create($data + ['tenant_id' => $tenant->id]));

        $catalog = [
            ['Nasi Goreng Kampung', 'MKN-001', '899100100001', 10000, 18000, 28, 8, 'porsi', 0, 'menu'],
            ['Mie Goreng Jawa', 'MKN-002', '899100100002', 9000, 17000, 24, 8, 'porsi', 0, 'menu'],
            ['Ayam Geprek Sambal Bawang', 'MKN-003', '899100100003', 12000, 22000, 20, 6, 'porsi', 0, 'menu'],
            ['Ayam Penyet Sambal Terasi', 'MKN-004', '899100100004', 12500, 23000, 18, 6, 'porsi', 0, 'menu'],
            ['Soto Ayam', 'MKN-005', '899100100005', 9000, 18000, 22, 6, 'mangkok', 0, 'menu'],
            ['Pecel Lele', 'MKN-006', '899100100006', 11000, 21000, 16, 5, 'porsi', 0, 'menu'],
            ['Nasi Putih', 'LUK-001', '899100200001', 2500, 5000, 40, 10, 'porsi', 1, 'menu'],
            ['Telur Ceplok', 'LUK-002', '899100200002', 3500, 6000, 30, 8, 'pcs', 1, 'menu'],
            ['Tempe Goreng', 'LUK-003', '899100200003', 1200, 3000, 35, 10, 'pcs', 1, 'menu'],
            ['Tahu Goreng', 'LUK-004', '899100200004', 1200, 3000, 35, 10, 'pcs', 1, 'menu'],
            ['Es Teh Manis', 'MNM-001', '899100300001', 1800, 5000, 50, 12, 'gelas', 2, 'menu'],
            ['Es Jeruk', 'MNM-002', '899100300002', 3000, 7000, 35, 10, 'gelas', 2, 'menu'],
            ['Kopi Hitam', 'MNM-003', '899100300003', 2200, 6000, 40, 10, 'cangkir', 2, 'menu'],
            ['Air Mineral', 'MNM-004', '899100300004', 2500, 4000, 45, 10, 'botol', 2, 'menu'],
            ['Pisang Goreng', 'CML-001', '899100400001', 1800, 4000, 26, 8, 'pcs', 3, 'menu'],
            ['Bakwan Sayur', 'CML-002', '899100400002', 1200, 3000, 30, 8, 'pcs', 3, 'menu'],
            ['Beras', 'BBK-001', '899100500001', 14500, 0, 40, 10, 'kg', 4, 'ingredient'],
            ['Ayam Potong', 'BBK-002', '899100500002', 38000, 0, 18, 5, 'kg', 4, 'ingredient'],
            ['Telur Ayam', 'BBK-003', '899100500003', 28000, 0, 120, 30, 'butir', 4, 'ingredient'],
            ['Minyak Goreng', 'BBK-004', '899100500004', 17000, 0, 24, 6, 'liter', 4, 'ingredient'],
            ['Cabai Rawit', 'BBK-005', '899100500005', 55000, 0, 8, 3, 'kg', 4, 'ingredient'],
            ['Bawang Merah', 'BBK-006', '899100500006', 42000, 0, 10, 3, 'kg', 4, 'ingredient'],
            ['Gula Pasir', 'BBK-007', '899100500007', 17500, 0, 15, 4, 'kg', 4, 'ingredient'],
            ['Teh Celup', 'BBK-008', '899100500008', 12000, 0, 80, 20, 'sachet', 4, 'ingredient'],
        ];

        $products = collect($catalog)->map(function ($row) use ($tenant, $main, $branch, $categories) {
            [$name,$sku,$barcode,$buy,$sell,$stock,$minimum,$unit,$cat,$productType] = $row;
            $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $categories[$cat]->id, 'name' => $name, 'product_type' => $productType, 'sku' => $sku, 'barcode' => $barcode, 'purchase_price' => $buy, 'selling_price' => $sell, 'minimum_stock' => $minimum, 'unit' => $unit, 'is_active' => true]);
            $stockModel = $productType === 'menu' ? DailyMenuStock::class : ProductStock::class;
            $stockModel::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'product_id' => $product->id, 'quantity' => $stock] + ($productType === 'menu' ? ['stock_date' => today()] : []));
            $stockModel::create(['tenant_id' => $tenant->id, 'store_id' => $branch->id, 'product_id' => $product->id, 'quantity' => max(2, (int) floor($stock * .6))] + ($productType === 'menu' ? ['stock_date' => today()] : []));

            return $product;
        });

        $member = Member::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-00001', 'qr_code' => (string) Str::uuid(), 'name' => 'Rina Andini', 'phone' => '081234567890', 'email' => 'rina@example.com', 'deposit_balance' => 150000, 'is_active' => true]);
        Member::create(['tenant_id' => $tenant->id, 'member_code' => 'MBR-00002', 'qr_code' => (string) Str::uuid(), 'name' => 'Pak Darto', 'phone' => '081298765432', 'deposit_balance' => 75000, 'is_active' => true]);
        $menuProducts = $products->where('product_type', 'menu')->values();

        foreach (range(6, 0) as $day) {
            foreach (range(1, $day % 3 + 2) as $index) {
                $product = $menuProducts[($day + $index) % $menuProducts->count()];
                $qty = ($index % 2) + 1;
                $total = $product->selling_price * $qty;
                $serviceType = ['dine_in', 'takeaway', 'online'][$index % 3];
                $trx = Transaction::create([
                    'tenant_id' => $tenant->id, 'store_id' => $main->id, 'user_id' => $owner->id,
                    'member_id' => $index === 1 ? $member->id : null,
                    'invoice_no' => 'TRX-DEMO-'.str_pad((7 - $day) * 10 + $index, 4, '0', STR_PAD_LEFT),
                    'service_type' => $serviceType, 'table_number' => $serviceType === 'dine_in' ? (string) (($index % 8) + 1) : null,
                    'online_platform' => $serviceType === 'online' ? ['GoFood', 'GrabFood', 'ShopeeFood'][$index % 3] : null,
                    'report_type' => 'real', 'subtotal' => $total, 'discount' => 0, 'total' => $total,
                    'payment_method' => ['cash', 'qris', 'transfer'][$index % 3], 'paid_amount' => $total, 'change_amount' => 0,
                    'transacted_at' => now()->subDays($day)->setTime(9 + $index, 15),
                ]);
                $trx->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty, 'price' => $product->selling_price, 'cost' => $product->purchase_price, 'subtotal' => $total]);
            }
        }

        Expense::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'user_id' => $owner->id, 'category' => 'Operasional', 'description' => 'Isi ulang gas dapur', 'amount' => 45000, 'report_type' => 'real', 'expense_date' => today()]);
        Expense::create(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'user_id' => $owner->id, 'category' => 'Transportasi', 'description' => 'Ongkos belanja bahan baku', 'amount' => 25000, 'report_type' => 'real', 'expense_date' => today()->subDay()]);

        DB::table('deposit_transactions')->insert(['tenant_id' => $tenant->id, 'store_id' => $main->id, 'member_id' => $member->id, 'user_id' => $owner->id, 'transaction_id' => null, 'type' => 'credit', 'amount' => 150000, 'balance_after' => 150000, 'description' => 'Saldo awal demo', 'created_at' => now(), 'updated_at' => now()]);
    }
}
