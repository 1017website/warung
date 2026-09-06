<?php
// Only an isolated audit schema is allowed; never use the operational database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'warungkita_audit_20260906') {
    throw new RuntimeException('Use warungkita_audit_20260906 only.');
}
$suffix = date('YmdHis');
$tenant = App\Models\Tenant::create(['name' => 'Warung Panduan', 'slug' => 'fix-'.$suffix]);
App\Models\Role::provisionDefaults($tenant->id);
$store = App\Models\Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Panduan', 'code' => 'FIX-'.$suffix, 'is_active' => true]);
$otherStore = App\Models\Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Kedua', 'code' => 'OTHER-'.$suffix, 'is_active' => true]);
$emails = [];
foreach ([...array_column(App\Models\Role::DEFAULTS, 'key'), 'transactions_only', 'dashboard_only'] as $role) {
    if (str_ends_with($role, '_only')) {
        App\Models\Role::create(['tenant_id' => $tenant->id, 'key' => $role, 'name' => $role, 'modules' => [str_replace('_only', '', $role)]]);
    }
    $user = App\Models\User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Demo '.str_replace('_', ' ', $role), 'email' => $role.'.'.$suffix.'@audit.test', 'role' => $role, 'is_active' => true, 'password' => 'password', 'authorization_pin' => Illuminate\Support\Facades\Hash::make('1234')]);
    $emails[$role] = $user->email;
}
$category = App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan', 'color' => '#78978a']);
$menu = App\Models\Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Ayam Bakar', 'sku' => 'AYAM', 'product_type' => 'menu', 'unit' => 'pcs', 'purchase_price' => 10000, 'selling_price' => 25000, 'online_selling_price' => 28000, 'minimum_stock' => 10, 'is_active' => true]);
App\Models\DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $menu->id, 'stock_date' => today()->subDay(), 'quantity' => 7]);
$raw = App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'Bahan baku', 'sku' => 'RAW', 'product_type' => 'ingredient', 'unit' => 'pak', 'selling_price' => 0, 'purchase_price' => 200000, 'is_active' => true]);
App\Models\ProductStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $raw->id, 'quantity' => 2]);
$purchase = App\Models\Purchase::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $user->id, 'purchase_no' => 'PO-FIX-'.$suffix, 'supplier_name' => 'Supplier Panduan', 'total' => 2000000, 'status' => 'received', 'payment_status' => 'unpaid', 'dp_amount' => 0, 'received_at' => now(), 'purchased_at' => today()]);
$purchase->items()->create(['product_id' => $raw->id, 'product_name' => $raw->name, 'quantity' => 10, 'unit_cost' => 200000, 'subtotal' => 2000000]);
$member = App\Models\Member::create(['tenant_id' => $tenant->id, 'name' => 'Member Demo', 'member_code' => 'MEM-'.$suffix, 'qr_code' => Illuminate\Support\Str::uuid(), 'deposit_balance' => 100000, 'discount_percent' => 0, 'is_active' => true]);
App\Models\MemberCard::create(['tenant_id' => $tenant->id, 'member_code' => 'CARD-'.$suffix, 'qr_code' => Illuminate\Support\Str::uuid(), 'status' => 'available']);
echo json_encode(['tenant' => $tenant->id, 'store' => $store->id, 'otherStore' => $otherStore->id, 'emails' => $emails, 'menu' => $menu->id, 'raw' => $raw->id, 'member' => $member->id, 'purchase' => $purchase->id, 'purchase_no' => $purchase->purchase_no, 'date' => today()->toDateString()]);
