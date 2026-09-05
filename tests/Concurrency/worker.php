<?php

// Dedicated test database only. Never load operational database credentials from .env.
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'warungkita_test', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (config('database.connections.mysql.database') !== 'warungkita_test') {
    throw new RuntimeException('Refusing to run outside warungkita_test. Clear config cache first.');
}

use App\Models\{Tenant, Store, Role, User, Product, DailyMenuStock, Member, Transaction};
use Illuminate\Support\Facades\{DB, Hash, Auth};

$mode = $argv[1];
if ($mode === 'seed') {
    $tag = 'load-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
    $tenant = Tenant::create(['name' => 'AUDIT '.$tag, 'slug' => $tag]);
    Role::provisionDefaults($tenant->id);
    $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Audit Pusat', 'code' => 'PST', 'is_active' => true]);
    $other = Store::create(['tenant_id' => $tenant->id, 'name' => 'Audit Cabang B', 'code' => 'CB', 'is_active' => true]);
    $users = [];
    foreach (['superadmin', 'cashier', 'head_ops'] as $role) {
        $users[$role] = User::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Audit '.$role, 'email' => $role.'@'.$tag.'.test', 'password' => 'AuditTest123!', 'authorization_pin' => Hash::make('1234'), 'role' => $role, 'is_active' => true]);
    }
    $products = [];
    foreach (['stock' => 10, 'deposit' => 40, 'void' => 0, 'adjust' => 5, 'reverse_a' => 40, 'reverse_b' => 40] as $name => $quantity) {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Audit '.$name, 'sku' => $name, 'unit' => 'porsi', 'product_type' => 'menu', 'selling_price' => 10000, 'purchase_price' => 4000, 'is_active' => true]);
        DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id, 'stock_date' => today(), 'quantity' => $quantity]);
        $products[$name] = $product->id;
    }
    $member = Member::create(['tenant_id' => $tenant->id, 'name' => 'Audit Member', 'member_code' => $tag, 'qr_code' => $tag, 'is_active' => true, 'deposit_balance' => 50000]);
    $trx = Transaction::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $users['cashier']->id, 'member_id' => $member->id, 'invoice_no' => $tag, 'status' => 'completed', 'transaction_type' => 'sale', 'report_type' => 'real', 'service_type' => 'takeaway', 'subtotal' => 10000, 'discount' => 0, 'total' => 10000, 'payment_method' => 'deposit', 'paid_amount' => 10000, 'change_amount' => 0, 'transacted_at' => now()]);
    $trx->items()->create(['product_id' => $products['void'], 'product_name' => 'Audit void', 'quantity' => 1, 'price' => 10000, 'cost' => 4000, 'subtotal' => 10000]);
    $trx->payments()->create(['method' => 'deposit', 'amount' => 10000]);
    echo json_encode(['tenant' => $tenant->id, 'store' => $store->id, 'other' => $other->id, 'user' => $users['superadmin']->id, 'emails' => array_map(fn ($u) => $u->email, $users), 'products' => $products, 'member' => $member->id, 'transaction' => $trx->id]);
} elseif ($mode === 'request') {
    $job = json_decode(file_get_contents($argv[2]), true);
    Auth::login(User::findOrFail($job['user']));
    while (microtime(true) < $job['start']) { usleep(1000); }
    $start = microtime(true);
    $request = Illuminate\Http\Request::create($job['url'], $job['method'], [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($job['data']));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'ms' => round((microtime(true) - $start) * 1000), 'message' => substr(json_decode($response->getContent(), true)['message'] ?? '', 0, 200)]);
} elseif ($mode === 'state') {
    $fixture = json_decode(file_get_contents($argv[2]), true);
    echo json_encode(['stocks' => DailyMenuStock::where('tenant_id', $fixture['tenant'])->pluck('quantity', 'product_id'), 'balance' => Member::findOrFail($fixture['member'])->deposit_balance, 'completed' => Transaction::where('tenant_id', $fixture['tenant'])->where('status', 'completed')->count(), 'voided' => Transaction::where('tenant_id', $fixture['tenant'])->where('status', 'voided')->count(), 'refunds' => DB::table('deposit_transactions')->where('transaction_id', $fixture['transaction'])->where('type', 'credit')->count(), 'movements' => DB::table('stock_movements')->where('tenant_id', $fixture['tenant'])->count()]);
}
